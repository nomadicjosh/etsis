<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Office\Repository\OfficeRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlOfficeRepository implements OfficeRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function office(EntityId $id): ?array
    {
        return $this->sql->current('sis_offices', $id->value);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_offices', $id->value, 'id');
    }

    public function saveOffice(array $facts, bool $new): void
    {
        try {
            if ($new) {
                $this->sql->insert('sis_offices', $facts);
            } else {
                $this->sql->update('sis_offices', $facts, ['id' => $facts['id']]);
            }
        } catch (\PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'code',
                    'message' => 'Use a unique office code. This code is already recorded.']]);
            }
            throw $error;
        }
    }

    public function offices(?EntityId $user, string $on, int $limit): array
    {
        $q = $this->sql->quote(...);
        $sql = 'SELECT o.* FROM ' . $q('sis_offices') . ' o';
        $parameters = [];
        if ($user !== null) {
            $sql .= ' JOIN ' . $q('sis_office_delegations') . ' d ON d.' . $q('office_id') . ' = o.' . $q('id')
            . ' WHERE o.' . $q('active') . ' = 1 AND d.' . $q('user_id') . ' = ?'
            . ' AND d.' . $q('active') . ' = 1 AND d.' . $q('starts_on') . ' <= ?'
            . ' AND (d.' . $q('ends_on') . ' IS NULL OR d.' . $q('ends_on') . ' >= ?)';
            $parameters = [$user->value, $on, $on];
        }
        return $this->sql->fetch($sql . ' ORDER BY o.' . $q('code'), $parameters, $limit);
    }

    public function delegation(EntityId $office, EntityId $user): ?array
    {
        $row = $this->sql->rows(
            'sis_office_delegations',
            ['office_id' => $office->value, 'user_id' => $user->value],
            1
        )[0] ?? null;
        // The relationship identity is stable; a locking reread sees revocation after a lock wait,
        // even when a database's ordinary SELECT still uses an earlier transaction snapshot.
        return $row === null ? null : $this->sql->current('sis_office_delegations', $row['id']);
    }

    public function delegates(EntityId $office, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT d.*, u.' . $q('first_name') . ', u.' . $q('last_name') . ' FROM '
            . $q('sis_office_delegations') . ' d JOIN ' . $q('users') . ' u ON u.' . $q('user_id')
            . ' = d.' . $q('user_id') . ' WHERE d.' . $q('office_id') . ' = ?'
            . ($after === null ? '' : ' AND d.' . $q('id') . ' > ?') . ' ORDER BY d.' . $q('id'),
            $after === null ? [$office->value] : [$office->value, $after->value],
            $limit
        );
    }

    public function saveDelegation(array $facts, bool $new): void
    {
        if ($new) {
            $this->sql->insert('sis_office_delegations', $facts);
        } else {
            $this->sql->update('sis_office_delegations', $facts, ['id' => $facts['id']]);
        }
    }

    public function userEnabled(EntityId $user): bool
    {
        return (int) ($this->sql->rows('users', ['user_id' => $user->value], 1)[0]['sign_in_enabled'] ?? 0) === 1;
    }

    public function people(string $search, bool $accountsOnly, int $limit): array
    {
        $q = $this->sql->quote(...);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $like = '%' . $escaped . '%';
        return $this->sql->fetch('SELECT p.' . $q('id') . ', p.' . $q('owner_user_id') . ', p.' . $q('given_name')
            . ', p.' . $q('family_name') . ', i.' . $q('institutional_id') . ' FROM ' . $q('sis_persons') . ' p'
            . ' LEFT JOIN ' . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = p.' . $q('id')
            . ($accountsOnly ? ' JOIN ' . $q('users') . ' u ON u.' . $q('user_id') . ' = p.' . $q('owner_user_id')
                . ' AND u.' . $q('sign_in_enabled') . ' = 1' : '')
            . ' WHERE (LOWER(p.' . $q('given_name') . ") LIKE ? ESCAPE '!' OR LOWER(p." . $q('family_name')
            . ") LIKE ? ESCAPE '!' OR LOWER(i." . $q('institutional_id') . ") LIKE ? ESCAPE '!')"
            . ' ORDER BY p.' . $q('family_name') . ', p.' . $q('id'), [$like, $like, $like], $limit);
    }

    public function owner(EntityId $restriction): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT o.* FROM ' . $q('sis_restriction_offices') . ' r JOIN '
            . $q('sis_offices') . ' o ON o.' . $q('id') . ' = r.' . $q('office_id')
            . ' WHERE r.' . $q('id') . ' = ?', [$restriction->value], 1)[0] ?? null;
    }

    public function bind(EntityId $restriction, EntityId $office): void
    {
        $this->sql->insert('sis_restriction_offices', ['id' => $restriction->value, 'office_id' => $office->value]);
    }

    public function move(EntityId $restriction, EntityId $from, EntityId $to): void
    {
        $work = $this->followUp($restriction);
        if (
            $this->sql->update(
                'sis_restriction_offices',
                ['office_id' => $to->value, 'assigned_user_id' => null, 'follow_up_on' => null,
                    'work_revision' => (int) ($work['work_revision'] ?? 0) + 1],
                ['id' => $restriction->value, 'office_id' => $from->value]
            ) !== 1
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException('The owning office changed.');
        }
    }

    public function followUp(EntityId $restriction): ?array
    {
        $row = $this->sql->current('sis_restriction_offices', $restriction->value);
        if ($row === null) {
            return null;
        }
        $q = $this->sql->quote(...);
        $user = $row['assigned_user_id'] === null ? null : ($this->sql->fetch(
            'SELECT ' . $q('first_name') . ', ' . $q('last_name') . ' FROM ' . $q('users')
                . ' WHERE ' . $q('user_id') . ' = ?',
            [$row['assigned_user_id']],
            1
        )[0] ?? null);
        return $row + ['assignee_name' => $user === null ? null
            : trim($user['first_name'] . ' ' . $user['last_name'])];
    }

    public function saveFollowUp(
        EntityId $restriction,
        EntityId $office,
        ?EntityId $assignee,
        ?string $on,
        int $revision,
    ): void {
        if (
            $this->sql->update('sis_restriction_offices', [
            'assigned_user_id' => $assignee?->value, 'follow_up_on' => $on, 'work_revision' => $revision + 1,
            ], ['id' => $restriction->value, 'office_id' => $office->value, 'work_revision' => $revision]) !== 1
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException('The office follow-up changed.');
        }
    }

    public function userCanManageHolds(EntityId $user): bool
    {
        return new SqlAccessRepository($this->sql)->allows(
            $user->value,
            \Domain\Office\Service\OfficeScope::HOLD_MANAGE
        );
    }

    public function workers(EntityId $office, string $on, string $search, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        $sql = 'SELECT d.' . $q('id') . ', d.' . $q('user_id') . ', u.' . $q('first_name')
        . ', u.' . $q('last_name') . ' FROM ' . $q('sis_office_delegations') . ' d JOIN ' . $q('users')
        . ' u ON u.' . $q('user_id') . ' = d.' . $q('user_id') . ' JOIN ' . $q('sis_offices')
        . ' o ON o.' . $q('id') . ' = d.' . $q('office_id') . ' WHERE d.' . $q('office_id') . ' = ?'
        . ' AND o.' . $q('active') . ' = 1 AND d.' . $q('active') . ' = 1 AND u.' . $q('sign_in_enabled') . ' = 1'
        . ' AND d.' . $q('starts_on') . ' <= ? AND (d.' . $q('ends_on') . ' IS NULL'
        . ' OR d.' . $q('ends_on') . ' >= ?)';
        $parameters = [$office->value, $on, $on];
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)) . '%';
            $sql .= ' AND (LOWER(u.' . $q('first_name') . ") LIKE ? ESCAPE '!' OR LOWER(u."
            . $q('last_name') . ") LIKE ? ESCAPE '!')";
            array_push($parameters, $like, $like);
        }
        if ($after !== null) {
            $sql .= ' AND d.' . $q('id') . ' > ?';
            $parameters[] = $after->value;
        }
        return $this->sql->fetch($sql . ' ORDER BY d.' . $q('id'), $parameters, $limit);
    }
}
