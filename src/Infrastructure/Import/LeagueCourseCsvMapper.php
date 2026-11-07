<?php

declare(strict_types=1);

namespace Infrastructure\Import;

use Domain\Catalog\Course;
use Domain\Import\Service\CourseCsvMapper;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use League\Csv\Reader;
use League\Csv\Writer;

final readonly class LeagueCourseCsvMapper implements CourseCsvMapper
{
    public function prepare(#[\SensitiveParameter] string $csv, array $mapping, string $delimiter): array
    {
        if (
            strlen($csv) > 1048576 || !mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")
            || !in_array($delimiter, [',', ';', "\t"], true)
        ) {
            throw new InvalidArgumentException('Choose a UTF-8 CSV file up to 1 MB.');
        }
        $reader = Reader::fromString($csv)->setDelimiter($delimiter)->setEscape('');
        $header = $reader->nth(0);
        if (count($header) < 1 || count($header) > 40 || count(array_unique($header)) !== count($header)) {
            throw new InvalidArgumentException('The CSV needs 1–40 distinct column headers.');
        }
        foreach ($header as $label) {
            if (
                trim($label) === '' || mb_strlen($label) > 120
                || preg_match('/[\x00-\x1f\x7f]/', $label)
            ) {
                throw new InvalidArgumentException('A CSV column header is invalid.');
            }
        }
        $targets = ['external_id', 'code', 'title', 'credits'];
        $errors = [];
        if ($mapping !== []) {
            if (array_diff(array_keys($mapping), $targets) !== [] || count(array_unique($mapping)) !== 4) {
                throw new InvalidArgumentException('Map each destination to a different source column.');
            }
            foreach ($targets as $target) {
                if (!isset($mapping[$target]) || !isset($header[$mapping[$target]])) {
                    $errors[] = ['field' => $target, 'message' => 'Choose a source column.'];
                }
            }
        }
        $writer = Writer::fromString()->setEscape('');
        $writer->insertOne(['external_id', 'code', 'title', 'credits_milli']);
        $samples = [];
        $count = 0;
        $seen = [];
        foreach ($reader->getRecords() as $offset => $row) {
            if ($offset === 0) {
                continue;
            }
            if (++$count > 1000) {
                throw new InvalidArgumentException('Import up to 1,000 rows at a time.');
            }
            if (count($samples) < 5) {
                $samples[] = $row;
            }
            if (count($row) !== count($header)) {
                $errors[] = ['field' => 'row_' . ($offset + 1),
                    'message' => 'Row ' . ($offset + 1) . ' has the wrong number of columns.'];
                continue;
            }
            if ($mapping === [] || count($mapping) !== 4 || count($errors) > 30) {
                continue;
            }
            try {
                $external = trim($row[$mapping['external_id']] ?? '');
                $code = trim($row[$mapping['code']] ?? '');
                $title = trim($row[$mapping['title']] ?? '');
                $credits = trim($row[$mapping['credits']] ?? '');
                if (
                    preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $external) !== 1 || isset($seen[$external])
                    || preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,3}))?$/D', $credits, $parts) !== 1
                ) {
                    throw new InvalidArgumentException('Invalid external ID or credit value.');
                }
                $milli = (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
                new Course(EntityId::generate(), $code, $title, $milli);
                $seen[$external] = true;
                $writer->insertOne([$external, $code, $title, (string) $milli]);
            } catch (InvalidArgumentException) {
                $errors[] = ['field' => 'row_' . ($offset + 1),
                    'message' => 'Row ' . ($offset + 1) . ': check the unique external ID, course code, title '
                        . 'and numeric credits (up to three decimal places).'];
            }
        }
        if ($count === 0) {
            $errors[] = ['field' => 'file', 'message' => 'The CSV contains no data rows.'];
        }
        ksort($mapping);

        return ['headers' => $header, 'samples' => $samples, 'row_count' => $count,
            'errors' => array_slice($errors, 0, 30), 'ready' => $mapping !== [] && $errors === [],
            'fingerprint' => hash('sha256', json_encode([$csv, $mapping, $delimiter], JSON_THROW_ON_ERROR)),
            'canonical_csv' => $writer->toString()];
    }
}
