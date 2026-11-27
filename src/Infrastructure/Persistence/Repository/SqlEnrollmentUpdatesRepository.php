<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Registration\Repository\EnrollmentUpdatesRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlEnrollmentUpdatesRepository implements EnrollmentUpdatesRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function replace(array $reference): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_enrollment_updates') . ' WHERE ' . $q('enrollment_id') . ' = ?', [
            $reference['enrollment_id'],
        ]);
        $this->sql->insert('sis_enrollment_updates', $reference);
    }

    public function current(EntityId $enrollment): ?array
    {
        return $this->sql->rows('sis_enrollment_updates', ['enrollment_id' => $enrollment->value], 1)[0] ?? null;
    }

    private function from(): string
    {
        $q = $this->sql->quote(...);
        return ' FROM ' . $q('sis_enrollment_updates') . ' n JOIN ' . $q('sis_students') . ' s ON s.'
        . $q('id') . ' = n.' . $q('student_id') . ' AND s.' . $q('person_id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_registration_streams') . ' a ON a.' . $q('id') . ' = n.' . $q('enrollment_id')
        . ' AND a.' . $q('student_id') . ' = n.' . $q('student_id')
        . ' AND a.' . $q('section_id') . ' = n.' . $q('section_id')
        . ' JOIN ' . $q('sis_sections') . ' c ON c.' . $q('id') . ' = a.' . $q('section_id')
        . ' JOIN ' . $q('sis_courses') . ' g ON g.' . $q('id') . ' = c.' . $q('course_id')
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
            array_push($args, $afterAt, $afterAt, $after->value);
        }
        $fields = array_map(
            static fn (string $f): string => 'n.' . $q($f),
            ['id', 'revision', 'status', 'recorded_at', 'read_at']
        );
        return $this->sql->fetch(
            'SELECT ' . implode(', ', $fields) . ', g.' . $q('code') . ' AS ' . $q('course_code')
            . ', g.' . $q('title') . ' AS ' . $q('course_title')
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
        $this->sql->lock('sis_enrollment_updates', $notice->value, 'revision');
    }

    public function delete(EntityId $owner, EntityId $person, EntityId $notice): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'DELETE FROM ' . $q('sis_enrollment_updates') . ' WHERE ' . $q('id')
            . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('person_id') . ' = ?',
            [$notice->value, $owner->value, $person->value]
        );
    }

    public function markRead(EntityId $owner, EntityId $notice, string $at): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'UPDATE ' . $q('sis_enrollment_updates') . ' SET ' . $q('read_at') . ' = ? WHERE '
            . $q('id') . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('read_at') . ' IS NULL',
            [$at, $notice->value, $owner->value]
        );
    }
}
