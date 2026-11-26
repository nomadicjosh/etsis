<?php

declare(strict_types=1);

namespace Infrastructure\Import;

use Domain\Import\Service\PersonCsvMapper;
use InvalidArgumentException;
use League\Csv\Reader;

use function Codefy\Framework\Helpers\trans;

final readonly class LeaguePersonCsvMapper implements PersonCsvMapper
{
    public function prepare(
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter,
    ): array {
        if (
            $csv === "" ||
            strlen($csv) > 2097152 ||
            !mb_check_encoding($csv, "UTF-8") ||
            str_contains($csv, "\0") ||
            !in_array($delimiter, [",", ";", "\t"], true)
        ) {
            throw new InvalidArgumentException(
                trans("Choose a UTF-8 people CSV up to 2 MB."),
            );
        }
        $reader = Reader::fromString($csv)
            ->setDelimiter($delimiter)
            ->setEscape("");
        $headers = array_values($reader->nth(0));
        if (
            $headers === [] ||
            count($headers) > 40 ||
            count(array_unique($headers)) !== count($headers)
        ) {
            throw new InvalidArgumentException(
                trans("Use 1–40 distinct CSV column headers."),
            );
        }
        foreach ($headers as $label) {
            if (
                !is_string($label) ||
                trim($label) === "" ||
                mb_strlen($label) > 120 ||
                preg_match('/[\x00-\x1F\x7F]/', $label)
            ) {
                throw new InvalidArgumentException(
                    trans(
                        "Use printable CSV column headers up to 120 characters.",
                    ),
                );
            }
        }
        if ($mapping !== []) {
            $keys = array_keys($mapping);
            sort($keys);
            if ($keys !== ["email", "family_name", "given_name"]) {
                throw new InvalidArgumentException(
                    trans(
                        "Map each required person field to a distinct CSV column.",
                    ),
                );
            }
            foreach ($mapping as $index) {
                if (
                    !is_int($index) ||
                    $index < 0 ||
                    $index >= count($headers)
                ) {
                    throw new InvalidArgumentException(
                        trans(
                            "Choose valid CSV columns for all three person fields.",
                        ),
                    );
                }
            }
            if (count(array_unique($mapping)) !== 3) {
                throw new InvalidArgumentException(
                    trans(
                        "Map each required person field to a distinct CSV column.",
                    ),
                );
            }
        }
        $rows = [];
        $first = true;
        foreach ($reader->getRecords() as $values) {
            if ($first) {
                $first = false;
                continue;
            }
            if (count($rows) >= 5000 || count($values) !== count($headers)) {
                throw new InvalidArgumentException(
                    trans(
                        "Use at most 5,000 people with the same column count as the header.",
                    ),
                );
            }
            $row = ["row" => count($rows) + 2];
            foreach ($mapping as $field => $index) {
                $row[$field] = (string) ($values[$index] ?? "");
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new InvalidArgumentException(
                trans("Include at least one person after the CSV header."),
            );
        }
        return ["headers" => $headers, "rows" => $mapping === [] ? [] : $rows];
    }
}
