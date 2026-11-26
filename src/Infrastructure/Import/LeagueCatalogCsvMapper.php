<?php

declare(strict_types=1);

namespace Infrastructure\Import;

use Domain\Import\ValueObject\CatalogImportRow;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Import\Service\CatalogCsvMapper;
use InvalidArgumentException;
use League\Csv\Reader;

use function Codefy\Framework\Helpers\trans;

final readonly class LeagueCatalogCsvMapper implements CatalogCsvMapper
{
    public function prepare(
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter,
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array {
        if (
            strlen($csv) > 2097152 ||
            !mb_check_encoding($csv, "UTF-8") ||
            str_contains($csv, "\0") ||
            !in_array($delimiter, [",", ";", "\t"], true)
        ) {
            throw new InvalidArgumentException(trans("Choose a UTF-8 CSV file up to 2 MB."));
        }
        $reader = Reader::fromString($csv)->setDelimiter($delimiter)->setEscape("");
        $header = $this->values($reader->nth(0));
        if (count($header) < 1 || count($header) > 40 || count(array_unique($header)) !== count($header)) {
            throw new InvalidArgumentException("The CSV needs 1–40 distinct column headers.");
        }
        foreach ($header as $label) {
            if (trim($label) === "" || mb_strlen($label) > 120 || preg_match('/[\x00-\x1f\x7f]/', $label)) {
                throw new InvalidArgumentException("A CSV column header is invalid.");
            }
        }
        $targets =
        $target === CatalogImportTarget::Course
        ? ["external_id", "code", "title", "credits"]
        : ["external_id", "code", "title"];
        $optional = array_map(
            static fn(string $kind): string => $kind . "_code",
            array_keys($target->references()),
        );
        if ($target === CatalogImportTarget::Program) {
            $optional[] = "description";
        }
        if ($target === CatalogImportTarget::Course) {
            $optional[] = 'course_number';
        }
        $errors = [];
        if ($mapping !== []) {
            if (
                array_diff(array_keys($mapping), [...$targets, ...$optional]) !== [] ||
                count(array_unique(array_intersect_key($mapping, array_flip($targets)))) !== count($targets)
            ) {
                throw new InvalidArgumentException("Map each destination to a different source column.");
            }
            foreach ($targets as $field) {
                if (!isset($mapping[$field]) || !isset($header[$mapping[$field]])) {
                    $errors[] = ["field" => $field, "message" => "Choose a source column."];
                }
            }
            foreach ($optional as $field) {
                if (isset($mapping[$field]) && !isset($header[$mapping[$field]])) {
                    $errors[] = [
                        "field" => $field,
                        "message" => "Choose an existing column or leave it unmapped.",
                    ];
                }
            }
        }
        $mappingValid = $errors === [];
        $rows = [];
        $samples = [];
        $count = 0;
        $seen = [];
        foreach ($reader->getRecords() as $offset => $row) {
            $row = $this->values($row);
            if ($offset === 0) {
                continue;
            }
            if (++$count > 5000) {
                throw new InvalidArgumentException(trans("Import up to 5,000 rows at a time."));
            }
            if (count($samples) < 5) {
                $samples[] = $row;
            }
            if (count($row) !== count($header)) {
                $errors[] = [
                    "field" => "row_" . ($offset + 1),
                    "message" => "Row " . ($offset + 1) . " has the wrong number of columns.",
                ];
                continue;
            }
            if (
                !$mappingValid ||
                $mapping === [] ||
                array_diff($targets, array_keys($mapping)) !== [] ||
                count($errors) > 30
            ) {
                continue;
            }
            try {
                $external = trim($row[$mapping["external_id"]] ?? "");
                $code = trim($row[$mapping["code"]] ?? "");
                if ($target !== CatalogImportTarget::Course) {
                    $code = strtoupper($code);
                }
                $title = trim($row[$mapping["title"]] ?? "");
                $credits =
                $target === CatalogImportTarget::Course ? trim($row[$mapping["credits"]] ?? "") : null;
                if (
                    preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $external) !== 1 ||
                    isset($seen[strtolower($external)]) ||
                    ($target === CatalogImportTarget::Course &&
                        preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,3}))?$/D', $credits, $parts) !== 1)
                ) {
                    throw new InvalidArgumentException("Invalid external ID or credit value.");
                }
                $milli =
                $credits === null
                ? null
                : (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? "", 3, "0");
                $references = [];
                foreach ($target->references() as $kind => $label) {
                    if (isset($mapping[$kind . "_code"])) {
                        $references[$kind] = $row[$mapping[$kind . "_code"]];
                    }
                }
                $descriptionMapped =
                $target === CatalogImportTarget::Program && isset($mapping["description"]);
                $description = $descriptionMapped ? trim($row[$mapping["description"]]) : null;
                $numberMapped = $target === CatalogImportTarget::Course && isset($mapping['course_number']);
                $number = $numberMapped ? trim($row[$mapping['course_number']]) : null;
                $course = new CatalogImportRow(
                    $offset + 1,
                    $external,
                    $code,
                    $title,
                    $milli,
                    $references,
                    $target,
                    $description === "" ? null : $description,
                    $descriptionMapped,
                    courseNumber: $number === '' ? null : $number,
                    numberMapped: $numberMapped,
                );
                $seen[strtolower($external)] = true;
                $rows[] = $course;
            } catch (InvalidArgumentException) {
                $errors[] = [
                    "field" => "row_" . ($offset + 1),
                    "message" =>
                        "Row " .
                        ($offset + 1) .
                        ": check the unique external ID, " .
                        $target->value .
                        " code and title. " .
                        ($target === CatalogImportTarget::Course
                            ? "Use credits with up to three decimal places and a course number up to 32 characters. "
                            : ($target === CatalogImportTarget::Program
                                ? "Use a plain-text description of up to 10000 characters. "
                                : "Use a code up to 40 characters and a name up to 191 characters. ")) .
                        "Use classification codes of up to 40 characters.",
                ];
            }
        }
        if ($count === 0) {
            $errors[] = ["field" => "file", "message" => "The CSV contains no data rows."];
        }
        ksort($mapping);

        return [
            "headers" => $header,
            "samples" => $samples,
            "row_count" => $count,
            "errors" => array_slice($errors, 0, 30),
            "ready" => $mapping !== [] && $errors === [],
            "fingerprint" => hash("sha256", json_encode([$csv, $mapping, $delimiter], JSON_THROW_ON_ERROR)),
            "rows" => $rows,
        ];
    }
    /** @param array<array-key,mixed> $row
     * @return list<string> */
    private function values(array $row): array
    {
        $values = [];
        foreach ($row as $value) {
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException("A CSV cell is invalid.");
            }
            $values[] = $value ?? "";
        }
        return $values;
    }
}
