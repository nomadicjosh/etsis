<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\Repository\AdvisingUpdatesRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdvisingUpdatesRepository implements AdvisingUpdatesRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function replace(array $reference): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'DELETE FROM ' . $q('sis_advising_updates') . ' WHERE ' . $q('checkin_id') . ' = ?',
            [$reference['checkin_id']]
        );
        $this->sql->insert('sis_advising_updates', $reference);
    }

    public function current(EntityId $checkin): ?array
    {
        return $this->sql->rows('sis_advising_updates', ['checkin_id' => $checkin->value], 1)[0] ?? null;
    }

    public function respond(EntityId $checkin, EntityId $owner, string $at): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'UPDATE ' . $q('sis_advising_updates') . ' SET ' . $q('read_at') . ' = ? WHERE '
            . $q('checkin_id') . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('read_at') . ' IS NULL',
            [$at, $checkin->value, $owner->value]
        );
    }

    private function from(): string
    {
        $q = $this->sql->quote(...);
        return ' FROM ' . $q('sis_advising_updates') . ' n JOIN ' . $q('sis_students') . ' s ON s.'
        . $q('id') . ' = n.' . $q('student_id') . ' AND s.' . $q('person_id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = n.' . $q('person_id')
        . ' JOIN ' . $q('sis_advising_checkins') . ' c ON c.' . $q('id') . ' = n.' . $q('checkin_id')
        . ' AND c.' . $q('student_id') . ' = n.' . $q('student_id')
        . ' AND c.' . $q('pursuit_id') . ' = n.' . $q('pursuit_id')
        . ' AND c.' . $q('owner_user_id') . ' = n.' . $q('owner_user_id')
        . ' JOIN ' . $q('sis_student_programs') . ' a ON a.' . $q('id') . ' = n.' . $q('pursuit_id')
        . ' AND a.' . $q('student_id') . ' = n.' . $q('student_id')
        . ' JOIN ' . $q('sis_programs') . ' g ON g.' . $q('id') . ' = a.' . $q('program_id');
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
        return $this->sql->fetch(
            'SELECT n.*, g.' . $q('code') . ' AS ' . $q('program_code') . ', g.' . $q('title') . ' AS '
            . $q('program_title') . $this->from() . ' WHERE ' . implode(' AND ', $where)
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
        $this->sql->lock('sis_advising_updates', $notice->value, 'revision');
    }

    public function delete(EntityId $owner, EntityId $person, EntityId $notice): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'DELETE FROM ' . $q('sis_advising_updates') . ' WHERE ' . $q('id')
            . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('person_id') . ' = ?',
            [$notice->value, $owner->value, $person->value],
        );
    }

    public function markRead(EntityId $owner, EntityId $notice, string $at): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'UPDATE ' . $q('sis_advising_updates') . ' SET ' . $q('read_at') . ' = ? WHERE '
            . $q('id') . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('read_at') . ' IS NULL',
            [$at, $notice->value, $owner->value],
        );
    }
}
