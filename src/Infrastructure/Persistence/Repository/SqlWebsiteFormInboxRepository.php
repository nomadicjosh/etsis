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

    public function page(EntityId $actor, ?EntityId $after = null, string $state = 'all'): array
    {
        $q = $this->sql->quote(...);
        $where = [$this->scopeSql()];
        $args = [$actor->value];
        if ($after !== null) {
            $where[] = $q('id') . ' < ?';
            $args[] = $after->value;
        }
        if ($state !== 'all') {
            $where[] = $q('status') . ' = ?';
            $args[] = $state;
        }
        return $this->sql->fetch('SELECT ' . implode(', ', array_map(
            $q,
            ['id', 'page_id', 'form_key', 'department_id', 'page_title', 'form_title', 'submitted_at',
                'status', 'version']
        ))
            . ' FROM ' . $q('sis_website_form_inbox') . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $q('id') . ' DESC', $args, 21);
    }

    /** Filter before pagination, so metadata and page counts obey the same boundary as answers. */
    private function scopeSql(): string
    {
        $q = $this->sql->quote(...);
        $inbox = static fn (string $key): string => $q('sis_website_form_inbox') . '.' . $q($key);
        $grant = static fn (string $key): string => $q('g') . '.' . $q($key);
        return 'EXISTS (SELECT 1 FROM ' . $q('sis_website_form_access') . ' ' . $q('g')
        . ' WHERE ' . $grant('user_id') . ' = ? AND (('
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
        if (
            $this->sql->execute('DELETE FROM ' . $q('sis_website_form_inbox') . ' WHERE ' . $q('id')
            . ' = ? AND ' . $q('version') . ' = ?', [$id->value, $version]) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }
}
