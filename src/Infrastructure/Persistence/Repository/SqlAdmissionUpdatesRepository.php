<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionUpdatesRepository implements AdmissionUpdatesRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function record(EntityId $application, EntityId $owner, string $at): void
    {
        $where = ['application_id' => $application->value, 'owner_user_id' => $owner->value];
        $row = $this->sql->rows('sis_admission_updates', $where, 1)[0] ?? null;
        if ($row === null) {
            $this->sql->insert('sis_admission_updates', ['id' => EntityId::generate()->value] + $where
                + ['revision' => 1, 'read_revision' => 0, 'updated_at' => $at]);
            return;
        }
        if ((int) $row['revision'] >= 2000000000) {
            throw new \RuntimeException('Admission updates need maintenance.');
        }
        $this->sql->update(
            'sis_admission_updates',
            ['revision' => (int) $row['revision'] + 1, 'updated_at' => $at],
            $where
        );
    }
    private function from(): string
    {
        $q = $this->sql->quote(...);
        return ' FROM ' . $q('sis_admission_updates') . ' n JOIN ' . $q('sis_applications') . ' a ON a.'
        . $q('id') . ' = n.' . $q('application_id') . ' JOIN ' . $q('sis_persons') . ' p ON p.'
        . $q('id') . ' = a.' . $q('person_id') . ' JOIN ' . $q('sis_programs') . ' g ON g.' . $q('id')
        . ' = a.' . $q('program_id') . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id')
        . ' = a.' . $q('entry_term_id');
    }
    private function ownerWhere(): string
    {
        $q = $this->sql->quote(...);
        return 'n.' . $q('owner_user_id') . ' = ? AND p.' . $q('owner_user_id') . ' = ?';
    }
    public function page(EntityId $owner, bool $unreadOnly, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [$this->ownerWhere()];
        $args = [$owner->value, $owner->value];
        if ($unreadOnly) {
            $where[] = 'n.' . $q('read_revision') . ' < n.' . $q('revision');
        }
        if ($afterAt !== null && $after !== null) {
            $where[] = '(n.' . $q('updated_at') . ' < ? OR (n.' . $q('updated_at')
            . ' = ? AND n.' . $q('id') . ' < ?))';
            array_push($args, $afterAt, $afterAt, $after->value);
        }
        $columns = array_map(
            static fn (string $field): string => 'n.' . $q($field),
            ['id', 'application_id', 'revision', 'read_revision', 'updated_at']
        );
        return $this->sql->fetch('SELECT ' . implode(', ', $columns) . ', g.' . $q('code') . ' AS ' . $q('program_code')
            . ', g.' . $q('title') . ' AS ' . $q('program_title') . ', t.' . $q('code') . ' AS ' . $q('term_code')
            . $this->from() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.' . $q('updated_at')
            . ' DESC, n.' . $q('id') . ' DESC', $args, 21);
    }
    public function unreadCount(EntityId $owner): int
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT COUNT(*) AS ' . $q('total') . $this->from() . ' WHERE ' . $this->ownerWhere()
            . ' AND n.' . $q('read_revision') . ' < n.' . $q('revision'), [$owner->value, $owner->value], 1);
        return (int) ($rows[0]['total'] ?? 0);
    }
    public function owned(EntityId $owner, EntityId $notice): ?array
    {
        $q = $this->sql->quote(...);
        $fields = array_map(
            static fn (string $field): string => 'n.' . $q($field),
            ['id', 'application_id', 'revision', 'read_revision']
        );
        return $this->sql->fetch(
            'SELECT ' . implode(', ', $fields) . $this->from()
            . ' WHERE ' . $this->ownerWhere() . ' AND n.' . $q('id') . ' = ?',
            [$owner->value, $owner->value, $notice->value],
            1
        )[0] ?? null;
    }
    public function lock(EntityId $notice): void
    {
        $this->sql->lock('sis_admission_updates', $notice->value, 'revision');
    }
    public function markRead(EntityId $owner, EntityId $notice, int $revision): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('UPDATE ' . $q('sis_admission_updates') . ' SET ' . $q('read_revision') . ' = ? WHERE '
            . $q('id') . ' = ? AND ' . $q('owner_user_id') . ' = ? AND ' . $q('read_revision') . ' < ? AND '
            . $q('revision') . ' >= ?', [$revision, $notice->value, $owner->value, $revision, $revision]);
    }
}
