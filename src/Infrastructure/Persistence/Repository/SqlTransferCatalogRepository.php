<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCatalogRepository;
use DomainException;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use PDOException;

final readonly class SqlTransferCatalogRepository implements TransferCatalogRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    private function table(string $kind): string
    {
        return match ($kind) {
            'institutions' => 'sis_external_institutions', 'external' => 'sis_external_courses',
            'equivalencies' => 'sis_transfer_equivalencies', 'courses' => 'sis_courses',
            default => throw new InvalidArgumentException('Unknown transfer catalog record.'),
        };
    }

    public function get(string $kind, EntityId $id): array
    {
        [$select, $from] = $this->definition($kind);
        $rows = $this->sql->fetch(
            'SELECT ' . $select . ' FROM ' . $from . ' WHERE r.' . $this->sql->quote('id') . ' = ?',
            [$id->value],
            1
        );
        $record = $rows[0] ?? throw new RecordNotFoundException('Transfer catalog record not found.');
        foreach (['minimum_grade_rank', 'credits_milli', 'points_milli', 'passes', 'counts_gpa'] as $field) {
            if (isset($record[$field])) {
                $record[$field] = (int) $record[$field];
            }
        }

        return $record;
    }

    public function lock(string $kind, EntityId $id): void
    {
        $this->sql->lock($this->table($kind), $id->value, 'id');
    }

    public function save(string $kind, EntityId $id, array $values): void
    {
        $allowed = match ($kind) {
            'institutions' => ['code', 'name'], 'external' => ['code', 'title'],
            'equivalencies' => ['starts_on', 'ends_on', 'minimum_grade_rank', 'credits_milli', 'grade_id'],
            default => throw new InvalidArgumentException('Unknown transfer editor.'),
        };
        if ($values === [] || array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported transfer fields.');
        }
        try {
            $this->sql->update($this->table($kind), $values, ['id' => $id->value]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code', 'message' => 'This code is already assigned.']]);
            }
            throw $error;
        }
    }

    public function passingGrade(string $code): EntityId
    {
        $grade = $this->sql->one('sis_grades', ['code' => $code]);
        if (!(bool) $grade['passes']) {
            throw new DomainException('A transfer award requires a passing grade.');
        }

        return new EntityId($grade['id']);
    }

    public function children(string $kind, EntityId $id, ?EntityId $after, int $limit): array
    {
        [$child, $field] = match ($kind) {
            'institutions' => ['external', 'institution_id'], 'external' => ['equivalencies', 'external_course_id'],
            default => throw new InvalidArgumentException('Unknown transfer child list.'),
        };
        [$select, $from] = $this->definition($child);
        $q = $this->sql->quote(...);

        return $this->sql->fetch(
            'SELECT ' . $select . ' FROM ' . $from . ' WHERE r.' . $q($field) . ' = ?'
            . ($after === null ? '' : ' AND r.' . $q('id') . ' < ?') . ' ORDER BY r.' . $q('id') . ' DESC',
            $after === null ? [$id->value] : [$id->value, $after->value],
            $limit
        );
    }

    public function awards(EntityId $id, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT r.' . $q('id') . ', r.' . $q('student_id') . ', r.' . $q('credit_id')
            . ', r.' . $q('completed_on') . ', p.' . $q('given_name') . ', p.' . $q('family_name')
            . ', i.' . $q('institutional_id') . ', t.' . $q('code') . ' AS ' . $q('term_code')
            . ', c.' . $q('status') . ', c.' . $q('version')
            . ', c.' . $q('completed_milli') . ' AS ' . $q('credits_milli') . ', c.' . $q('course_code')
            . ', c.' . $q('course_title') . ', g.' . $q('code') . ' AS ' . $q('grade_code')
            . ' FROM ' . $q('sis_transfer_awards') . ' r JOIN ' . $q('sis_students') . ' s ON s.' . $q('id')
            . ' = r.' . $q('student_id') . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id')
            . ' = s.' . $q('person_id') . ' JOIN ' . $q('sis_person_identifiers') . ' i ON i.'
            . $q('person_id') . ' = p.' . $q('id') . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id')
            . ' = r.' . $q('term_id') . ' JOIN ' . $q('sis_academic_credits') . ' c ON c.' . $q('id')
            . ' = r.' . $q('credit_id') . ' LEFT JOIN ' . $q('sis_grades') . ' g ON g.' . $q('id')
            . ' = c.' . $q('grade_id') . ' WHERE r.' . $q('equivalency_id') . ' = ?'
            . ($after === null ? '' : ' AND r.' . $q('id') . ' < ?') . ' ORDER BY r.' . $q('id') . ' DESC',
            $after === null ? [$id->value] : [$id->value, $after->value],
            $limit
        );
    }

    /** @return array{string, string} */
    public function definition(string $kind): array
    {
        $q = $this->sql->quote(...);
        return match ($kind) {
            'institutions' => ['r.*', $q('sis_external_institutions') . ' r'],
            'external' => ['r.*, i.' . $q('code') . ' AS ' . $q('institution_code')
                . ', i.' . $q('name') . ' AS ' . $q('institution_name'),
                $q('sis_external_courses') . ' r JOIN ' . $q('sis_external_institutions') . ' i ON i.'
                . $q('id') . ' = r.' . $q('institution_id')],
            'equivalencies' => ['r.*, e.' . $q('institution_id') . ', i.' . $q('code') . ' AS ' . $q('institution_code')
                . ', i.' . $q('name') . ' AS ' . $q('institution_name')
                . ', e.' . $q('code') . ' AS ' . $q('external_code')
                . ', e.' . $q('title') . ' AS ' . $q('external_title')
                . ', c.' . $q('code') . ' AS ' . $q('course_code')
                . ', c.' . $q('title') . ' AS ' . $q('course_title')
                . ', g.' . $q('code') . ' AS ' . $q('awarded_grade')
                . ', g.' . $q('points_milli') . ', g.' . $q('passes') . ', g.' . $q('counts_gpa'),
                $q('sis_transfer_equivalencies') . ' r JOIN ' . $q('sis_external_courses') . ' e ON e.' . $q('id')
                . ' = r.' . $q('external_course_id') . ' JOIN ' . $q('sis_external_institutions')
                . ' i ON i.' . $q('id')
                . ' = e.' . $q('institution_id') . ' JOIN ' . $q('sis_courses') . ' c ON c.' . $q('id')
                . ' = r.' . $q('course_id') . ' JOIN ' . $q('sis_grades')
                . ' g ON g.' . $q('id') . ' = r.' . $q('grade_id')],
            default => throw new InvalidArgumentException('Unknown transfer catalog record.'),
        };
    }
}
