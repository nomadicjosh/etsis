<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicRecord\Repository\AcademicUpdatesRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicUpdatesRepository implements AcademicUpdatesRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function replace(array $reference): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_academic_updates') . ' WHERE ' . $q('credit_id') . ' = ?', [
            $reference['credit_id'],
        ]);
        $this->sql->insert('sis_academic_updates', $reference);
    }

    public function current(EntityId $credit): ?array
    {
        return $this->sql->rows('sis_academic_updates', ['credit_id' => $credit->value], 1)[0] ?? null;
    }

    private function from(): string
    {
        $q = $this->sql->quote(...);
        return ' FROM ' . $q('sis_academic_updates') . ' n JOIN ' . $q('sis_students') . ' s ON s.'
        . $q('id') . ' = n.' . $q('student_id') . ' AND s.' . $q('person_id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_academic_credits') . ' a ON a.' . $q('id') . ' = n.' . $q('credit_id')
        . ' AND a.' . $q('student_id') . ' = n.' . $q('student_id')
        . ' AND a.' . $q('source_id') . ' = n.' . $q('enrollment_id')
        . ' JOIN ' . $q('sis_sections') . ' c ON c.' . $q('id') . ' = n.' . $q('section_id')
        . ' JOIN ' . $q('sis_courses') . ' g ON g.' . $q('id') . ' = a.' . $q('course_id')
        . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id') . ' = a.' . $q('term_id');
    }

    private function ownerWhere(): string
    {
        $q = $this->sql->quote(...);
        return 'n.' . $q('owner_user_id') . ' = ? AND p.' . $q('owner_user_id') . ' = ? AND n.'
        . $q('person_id') . ' = ?';
    }

    public function page(
        EntityId $owner,
        EntityId $person,
        bool $unreadOnly,
        ?string $afterAt = null,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        $where = [$this->ownerWhere()];
        $args = [$owner->value, $owner->value, $person->value];
        if ($unreadOnly) {
            $where[] = 'n.' . $q('read_at') . ' IS NULL';
        }
        if ($afterAt !== null && $after !== null) {
            $where[] = '(n.' . $q('recorded_at') . ' < ? OR (n.' . $q('recorded_at')
            . ' = ? AND n.' . $q('id') . ' < ?))';
            array_push($args, $afterAt, $after->value);
        }
        $fields = array_map(
            static fn (string $f): string => 'n.' . $q($f),
            ['id', 'revision', 'status', 'recorded_at', 'read_at', 'student_id',
                'credit_id', 'section_id', 'enrollment_id'],
        );
        return $this->sql->fetch(
            'SELECT ' . implode(', ', $fields)
            . ', a.' . $q('course_code') . ' AS ' . $q('course_code')
            . ', a.' . $q('course_title') . ' AS ' . $q('course_title')
            . ', c.' . $q('code') . ' AS ' . $q('section_code') . ', t.' . $q('code') . ' AS ' . $q('term_code')
            . $this->from() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY n.' . $q('recorded_at') . ' DESC, n.' . $q('id') . ' DESC',
            $args,
            21,
        );
    }

    public function owned(EntityId $owner, EntityId $person, EntityId $notice): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT n.*' . $this->from() . ' WHERE ' . $this->ownerWhere() . ' AND n.' . $q('id') . ' = ?',
            [$owner->value, $owner->value, $person->value, $notice->value],
            1,
        )[0] ?? null;
    }

    public function lock(EntityId $notice): void
    {
        $this->sql->lock('sis_academic_updates', $notice->value, 'revision');
    }

    public function delete(EntityId $owner, EntityId $person, EntityId $notice): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'DELETE FROM ' . $q('sis_academic_updates') . ' WHERE ' . $q('id')
            . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('person_id') . ' = ?',
            [$notice->value, $owner->value, $person->value],
        );
    }

    public function markRead(EntityId $owner, EntityId $notice, string $at): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'UPDATE ' . $q('sis_academic_updates') . ' SET ' . $q('read_at') . ' = ? WHERE '
            . $q('id') . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('read_at') . ' IS NULL',
            [$at, $notice->value, $owner->value],
        );
    }
}
