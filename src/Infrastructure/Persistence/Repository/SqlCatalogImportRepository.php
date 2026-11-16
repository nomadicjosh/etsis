<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Catalog\ValueObject\SubjectSnapshot;
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
        $referenceCodes = [];
        foreach ($rows as $row) {
            if (!isset($mappings['external:' . strtolower($row->externalId)])) {
                $codes[] = mb_strtolower($row->code);
                foreach ($row->references as $kind => $code) {
                    $referenceCodes[$kind][] = $code;
                }
            }
        }
        $courses = [];
        foreach (array_chunk(array_values(array_unique($codes)), 200) as $chunk) {
            $limit = count($chunk) * 2;
            $found = $this->sql->fetch(
                'SELECT c.' . $q('id') . ', c.' . $q('code') . ', c.' . $q('title')
                . ', c.' . $q('credits_milli') . ', c.' . $q('active') . ', s.' . $q('subject_id')
                . ', k.' . $q('department_id') . ', k.' . $q('academic_level_id') . ', k.' . $q('course_level_id')
                . ' FROM ' . $q('sis_courses') . ' c LEFT JOIN ' . $q('sis_course_subjects')
                . ' s ON s.' . $q('id') . ' = c.' . $q('id') . ' LEFT JOIN ' . $q('sis_course_classifications')
                . ' k ON k.' . $q('id') . ' = c.' . $q('id') . ' WHERE LOWER(c.' . $q('code') . ') IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
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
        return ['mappings' => $mappings, 'courses' => $courses,
            'references' => $this->references($referenceCodes)];
    }

    public function lockCourses(array $ids, bool $withReferences = false): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->sql->current('sis_courses', $id);
            if ($row === null) {
                throw new ConcurrentModificationException('A matched course was removed. Review the import again.');
            }
            $course = array_intersect_key($row, array_flip(['id', 'code', 'title', 'credits_milli', 'active']));
            if ($withReferences) {
                $subject = $this->sql->current('sis_course_subjects', $id);
                $classes = $this->sql->current('sis_course_classifications', $id);
                $course['subject_id'] = $subject['subject_id'] ?? null;
                foreach (ClassificationKind::courseKinds() as $kind) {
                    $course[$kind->value . '_id'] = $classes[$kind->value . '_id'] ?? null;
                }
            }
            $rows[] = $course;
        }
        return $rows;
    }

    public function lockReferences(array $references): array
    {
        $subjects = [];
        $classifications = [];
        foreach ($references as $reference) {
            if (!isset(CourseImportRow::REFERENCE_LABELS[$reference['kind']])) {
                throw new \InvalidArgumentException('Unknown catalog classification.');
            }
            if ($reference['kind'] === 'subject') {
                $subjects[$reference['id']] = true;
            } else {
                $classifications[$reference['id']] = true;
            }
        }
        ksort($subjects, SORT_STRING);
        ksort($classifications, SORT_STRING);
        $locked = [];
        // Match publication's course -> subject -> classification order across the complete batch.
        foreach (['sis_subjects' => $subjects, 'sis_catalog_classifications' => $classifications] as $table => $ids) {
            foreach (array_keys($ids) as $id) {
                $row = $this->sql->current($table, $id);
                if ($row === null) {
                    throw new ConcurrentModificationException(
                        'A catalog reference was removed. Review the import again.'
                    );
                }
                if ($table === 'sis_subjects') {
                    $row['kind'] = 'subject';
                }
                $locked[] = $row;
            }
        }
        return $locked;
    }

    /** @param array<string,list<string>> $codes
     * @return array<string,list<array<string,mixed>>> */
    private function references(array $codes): array
    {
        $q = $this->sql->quote(...);
        $references = [];
        foreach ($codes as $kind => $values) {
            $table = $kind === 'subject' ? 'sis_subjects' : 'sis_catalog_classifications';
            foreach (array_chunk(array_values(array_unique($values)), 200) as $chunk) {
                $found = $this->sql->fetch(
                    'SELECT ' . $q('id') . ', ' . $q('code') . ', ' . $q('title')
                    . ', ' . $q('active') . ' FROM ' . $q($table) . ' WHERE UPPER(' . $q('code') . ') IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')'
                    . ($kind === 'subject' ? '' : ' AND ' . $q('kind') . ' = ?'),
                    $kind === 'subject' ? $chunk : [...$chunk, $kind],
                    count($chunk) * 2 + 1
                );
                if (count($found) > count($chunk) * 2) {
                    throw new ValidationException([['field' => 'file',
                        'message' => 'Too many classification matches. Resolve ambiguous codes before importing.']]);
                }
                foreach ($found as $reference) {
                    $reference['kind'] = $kind;
                    $references[$kind . ':' . strtoupper($reference['code'])][] = $reference;
                }
            }
        }
        return $references;
    }

    public function create(string $source, CourseImportRow $row, array $references): EntityId
    {
        if (
            array_diff_key($row->references, $references) !== []
            || array_diff_key($references, $row->references) !== []
        ) {
            throw new \InvalidArgumentException('Every requested classification requires a reviewed reference.');
        }
        $course = $row->draft();
        $this->sql->insert('sis_courses', ['id' => $course->id->value, 'code' => $course->code,
            'title' => $course->title, 'credits_milli' => $course->creditsMilli, 'active' => 0]);
        if (isset($references['subject'])) {
            new SqlSubjectRepository($this->sql)->assign(
                $course->id,
                SubjectSnapshot::fromArray($references['subject'])->id
            );
        }
        $classes = [];
        foreach (ClassificationKind::courseKinds() as $kind) {
            $classes[$kind->value] = $references[$kind->value] ?? null;
        }
        if (array_filter($classes) !== []) {
            new SqlClassificationRepository($this->sql)->assign(
                $course->id,
                CourseClassifications::fromArray($classes)
            );
        }
        $this->link($source, $row, $course->id);
        return $course->id;
    }

    public function link(string $source, CourseImportRow $row, EntityId $course): void
    {
        $this->sql->insert('sis_import_mappings', ['id' => EntityId::generate()->value, 'source' => $source,
            'external_id' => $row->externalId, 'content_hash' => $row->fingerprint(), 'course_id' => $course->value]);
    }
}
