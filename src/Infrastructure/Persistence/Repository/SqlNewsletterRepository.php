<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\NewsletterRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlNewsletterRepository implements NewsletterRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function connections(?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map(
            $q,
            ['id', 'name', 'provider', 'enabled', 'version', 'target_hash']
        )) . ' FROM '
            . $q('sis_website_newsletters') . ($after === null ? '' : ' WHERE ' . $q('id') . ' > ?')
            . ' ORDER BY ' . $q('id'), $after === null ? [] : [$after->value], 21);
    }

    public function connection(EntityId $id): ?array
    {
        return $this->sql->rows('sis_website_newsletters', ['id' => $id->value], 1)[0] ?? null;
    }

    public function lockConnection(EntityId $id): void
    {
        $this->sql->lock('sis_website_newsletters', $id->value, 'version');
    }

    public function saveConnection(array $row, ?int $expected = null): void
    {
        if ($expected === null) {
            $this->sql->insert('sis_website_newsletters', $row);
        } elseif (
            $this->sql->update(
                'sis_website_newsletters',
                $row,
                ['id' => $row['id'], 'version' => $expected]
            ) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function enqueue(EntityId $inquiry, EntityId $connection, string $target, int $now): void
    {
        $this->sql->insert('sis_website_subscriptions', ['id' => $inquiry->value,
            'connection_id' => $connection->value, 'target_hash' => $target, 'state' => 'queued', 'issue' => 'none',
            'attempts' => 0, 'version' => 0, 'next_at' => $now, 'lease_until' => 0]);
    }

    public function job(EntityId $inquiry): ?array
    {
        return $this->sql->rows('sis_website_subscriptions', ['id' => $inquiry->value], 1)[0] ?? null;
    }

    public function next(int $now): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_website_subscriptions') . ' WHERE (('
            . $q('state') . ' IN (?, ?) AND ' . $q('next_at') . ' <= ?) OR (' . $q('state')
            . ' = ? AND ' . $q('lease_until') . ' <= ?)) ORDER BY ' . $q('id'),
            ['queued', 'retry', $now, 'sending', $now],
            1
        )[0] ?? null;
    }

    public function claim(EntityId $inquiry, int $version, int $now): bool
    {
        $row = $this->job($inquiry);
        if (
            $row === null || !in_array($row['state'], ['queued', 'retry'], true)
            || (int) $row['version'] !== $version || (int) $row['next_at'] > $now
        ) {
            return false;
        }
        return $this->sql->update(
            'sis_website_subscriptions',
            ['state' => 'sending',
            'version' => $version + 1, 'attempts' => (int) $row['attempts'] + 1, 'lease_until' => $now + 60],
            ['id' => $inquiry->value, 'version' => $version]
        ) === 1;
    }

    public function finish(EntityId $inquiry, int $version, string $state, string $issue, int $next): void
    {
        if (
            !in_array($state, ['queued', 'retry', 'completed', 'blocked', 'review'], true)
            || !in_array($issue, ['none', 'accepted', 'pending', 'already_member', 'suppressed',
                'retry', 'rejected', 'uncertain', 'disabled', 'changed', 'unreadable', 'interrupted'], true)
            || $this->sql->update(
                'sis_website_subscriptions',
                ['state' => $state, 'issue' => $issue,
                'next_at' => $next, 'lease_until' => 0, 'version' => $version + 1],
                ['id' => $inquiry->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function remove(EntityId $inquiry): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_website_subscriptions') . ' WHERE '
            . $q('id') . ' = ?', [$inquiry->value]);
    }
}
