<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlWebsiteFormInboxRepository implements WebsiteFormInboxRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function receipt(EntityId $id): ?array
    {
        return $this->sql->rows('sis_website_form_receipts', ['id' => $id->value], 1)[0] ?? null;
    }

    public function add(array $row, array $receipt): void
    {
        $this->sql->insert('sis_website_form_inbox', $row);
        $this->sql->insert('sis_website_form_receipts', $receipt);
    }

    public function recent(string $page, string $sessionHash, string $since): int
    {
        $q = $this->sql->quote(...);
        return count($this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_website_form_receipts')
            . ' WHERE ' . $q('page_id') . ' = ? AND ' . $q('session_hash') . ' = ? AND '
            . $q('accepted_at') . ' >= ?', [$page, $sessionHash, $since], 20));
    }

    public function prune(string $before, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException();
        }
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_website_form_receipts')
            . ' WHERE ' . $q('accepted_at') . ' < ? ORDER BY ' . $q('accepted_at'), [$before], $limit);
        $removed = 0;
        foreach ($rows as $row) {
            $removed += $this->sql->execute('DELETE FROM ' . $q('sis_website_form_receipts') . ' WHERE '
                . $q('id') . ' = ?', [$row['id']]);
        }
        return $removed;
    }

    public function page(
        EntityId $actor,
        ?EntityId $after = null,
        string $state = 'all',
        string $scope = 'all',
        ?EntityId $person = null,
    ): array {
        $q = $this->sql->quote(...);
        $root = $q('sis_website_form_inbox');
        $where = [$this->scopeSql()];
        $args = [$actor->value];
        if ($after !== null) {
            $where[] = $root . '.' . $q('id') . ' < ?';
            $args[] = $after->value;
        }
        if ($state !== 'all') {
            $where[] = $root . '.' . $q('status') . ' = ?';
            $args[] = $state;
        }
        if ($scope === 'mine') {
            $where[] = 'w.' . $q('assignee_id') . ' = ? AND w.' . $q('assignee_person_id') . ' = ?';
            array_push($args, $actor->value, $person?->value);
        } elseif ($scope === 'unassigned') {
            $where[] = 'w.' . $q('assignee_id') . ' IS NULL';
        }
        return $this->sql->fetch('SELECT ' . implode(', ', array_map(
            static fn (string $name): string => $root . '.' . $q($name),
            ['id', 'page_id', 'form_key', 'department_id', 'page_title', 'form_title', 'submitted_at',
                'status', 'version']
        ))
            . ', w.' . $q('assignee_id') . ', w.' . $q('assignee_person_id')
            . ', person.' . $q('given_name') . ', person.' . $q('family_name')
            . ', CASE WHEN ' . $this->scopeSql('w.' . $q('assignee_id')) . ' THEN 1 ELSE 0 END AS '
            . $q('assignee_scope') . ' FROM ' . $root . ' LEFT JOIN ' . $q('sis_website_inquiry_assignments')
            . ' w ON w.' . $q('id') . ' = ' . $root . '.' . $q('id')
            . ' LEFT JOIN ' . $q('sis_persons') . ' person ON person.' . $q('id') . ' = w.' . $q('assignee_person_id')
            . ' AND person.' . $q('owner_user_id') . ' = w.' . $q('assignee_id')
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $root . '.' . $q('id') . ' DESC', $args, 21);
    }

    /** Filter before pagination, so metadata and page counts obey the same boundary as answers. */
    private function scopeSql(string $user = '?'): string
    {
        $q = $this->sql->quote(...);
        $inbox = static fn (string $key): string => $q('sis_website_form_inbox') . '.' . $q($key);
        $grant = static fn (string $key): string => $q('g') . '.' . $q($key);
        return 'EXISTS (SELECT 1 FROM ' . $q('sis_website_form_access') . ' ' . $q('g')
        . ' WHERE ' . $grant('user_id') . ' = ' . $user . ' AND (('
        . $inbox('department_id') . ' IS NOT NULL AND ' . $grant('scope_kind') . " = 'department' AND "
        . $grant('scope_id') . ' = ' . $inbox('department_id') . ' AND ' . $grant('form_key') . " = ''"
        . ' AND EXISTS (SELECT 1 FROM ' . $q('sis_catalog_classifications') . ' ' . $q('d')
        . ' WHERE ' . $q('d') . '.' . $q('id') . ' = ' . $inbox('department_id')
        . ' AND ' . $q('d') . '.' . $q('kind') . " = 'department' AND "
        . $q('d') . '.' . $q('active') . ' = 1)) OR ('
        . $inbox('department_id') . ' IS NULL AND ' . $grant('scope_kind') . " = 'form' AND "
        . $grant('scope_id') . ' = ' . $inbox('page_id') . ' AND '
        . $grant('form_key') . ' = ' . $inbox('form_key') . ')))';
    }

    public function visible(EntityId $actor, EntityId $id): bool
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_website_form_inbox')
            . ' WHERE ' . $q('id') . ' = ? AND ' . $this->scopeSql(), [$id->value, $actor->value], 1) !== [];
    }

    public function get(EntityId $id): ?array
    {
        return $this->sql->rows('sis_website_form_inbox', ['id' => $id->value], 1)[0] ?? null;
    }

    public function assignment(EntityId $id): ?array
    {
        return $this->sql->rows('sis_website_inquiry_assignments', ['id' => $id->value], 1)[0] ?? null;
    }

    public function assign(EntityId $id, int $version, ?EntityId $user, ?EntityId $person, string $at): void
    {
        $values = ['version' => $version + 1, 'assignee_id' => $user?->value,
            'assignee_person_id' => $person?->value, 'updated_at' => $at];
        if ($version === 0) {
            $this->sql->insert('sis_website_inquiry_assignments', ['id' => $id->value] + $values);
        } elseif (
            $this->sql->update(
                'sis_website_inquiry_assignments',
                $values,
                ['id' => $id->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function assignees(EntityId $id, string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        [$query, $args] = new SqlAccessRepository($this->sql)->eligibleAccountQuery(
            ['sis:website:forms:read', 'sis:website:forms:manage'],
            $search,
            $after
        );
        return $this->sql->fetch('SELECT c.' . $q('id') . ', c.' . $q('person_id')
            . ', c.' . $q('given_name') . ', c.' . $q('family_name') . ' FROM (' . $query . ') c'
            . ' WHERE EXISTS (SELECT 1 FROM ' . $q('sis_website_form_inbox') . ' WHERE '
            . $q('sis_website_form_inbox') . '.' . $q('id') . ' = ? AND '
            . $this->scopeSql('c.' . $q('id')) . ') ORDER BY c.' . $q('id') . ' ASC', [...$args, $id->value], 21);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_website_form_inbox', $id->value, 'version');
    }

    public function update(EntityId $id, int $version, string $status): void
    {
        if (
            $this->sql->update(
                'sis_website_form_inbox',
                ['version' => $version + 1, 'status' => $status],
                ['id' => $id->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function delete(EntityId $id, int $version): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_website_inquiry_assignments') . ' WHERE '
            . $q('id') . ' = ?', [$id->value]);
        if (
            $this->sql->execute('DELETE FROM ' . $q('sis_website_form_inbox') . ' WHERE ' . $q('id')
            . ' = ? AND ' . $q('version') . ' = ?', [$id->value, $version]) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }
}
