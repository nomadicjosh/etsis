<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Import\Repository\CatalogImportRepository;
use Domain\Import\ValueObject\CourseImportRow;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCatalogImportRepository implements CatalogImportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function inspect(string $source, array $rows): array
    {
        $q = $this->sql->quote(...);
        $mappings = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $ids = array_map(static fn(CourseImportRow $r): string => strtolower($r->externalId), $chunk);
            $found = $this->sql->fetch('SELECT * FROM ' . $q('sis_import_mappings')
                . ' WHERE ' . $q('source') . ' = ? AND LOWER(' . $q('external_id') . ') IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')', [$source, ...$ids], count($ids) * 2 + 1);
            if (count($found) > count($ids) * 2) {
                throw new ValidationException([['field' => 'file',
                    'message' => 'Too many source identity matches. Resolve ambiguous IDs before importing.']]);
            }
            foreach ($found as $row) {
                $key = 'external:' . strtolower($row['external_id']);
                $mappings[$key] = isset($mappings[$key]) ? ['ambiguous' => true] : $row;
            }
        }
        $codes = [];
        foreach ($rows as $row) {
            if (!isset($mappings['external:' . strtolower($row->externalId)])) {
                $codes[] = mb_strtolower($row->code);
            }
        }
        $courses = [];
        foreach (array_chunk(array_values(array_unique($codes)), 200) as $chunk) {
            $limit = count($chunk) * 2;
            $found = $this->sql->fetch(
                'SELECT ' . $q('id') . ', ' . $q('code') . ', ' . $q('title')
                . ', ' . $q('credits_milli') . ', ' . $q('active') . ' FROM ' . $q('sis_courses')
                . ' WHERE LOWER(' . $q('code') . ') IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk,
                $limit + 1
            );
            if (count($found) > $limit) {
                throw new ValidationException([[
                    'field' => 'file',
                    'message' => 'Too many catalog matches. Reduce the file or resolve duplicate codes.',
                ]]);
            }
            foreach ($found as $course) {
                $courses[mb_strtolower($course['code'])][] = $course;
            }
        }
        return ['mappings' => $mappings, 'courses' => $courses];
    }

    public function lockCourses(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->sql->current('sis_courses', $id);
            if ($row === null) {
                throw new ConcurrentModificationException('A matched course was removed. Review the import again.');
            }
            $rows[] = array_intersect_key($row, array_flip(['id', 'code', 'title', 'credits_milli', 'active']));
        }
        return $rows;
    }

    public function create(string $source, CourseImportRow $row): EntityId
    {
        $course = $row->draft();
        $this->sql->insert('sis_courses', ['id' => $course->id->value, 'code' => $course->code,
            'title' => $course->title, 'credits_milli' => $course->creditsMilli, 'active' => 0]);
        $this->link($source, $row, $course->id);
        return $course->id;
    }

    public function link(string $source, CourseImportRow $row, EntityId $course): void
    {
        $this->sql->insert('sis_import_mappings', ['id' => EntityId::generate()->value, 'source' => $source,
            'external_id' => $row->externalId, 'content_hash' => $row->fingerprint(), 'course_id' => $course->value]);
    }
}
