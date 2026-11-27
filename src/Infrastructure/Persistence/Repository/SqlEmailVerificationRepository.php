<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\EmailVerificationRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlEmailVerificationRepository implements EmailVerificationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function account(EntityId $user): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . $q('user_id') . ', ' . $q('email') . ', ' . $q('sign_in_enabled')
            . ' FROM ' . $q('users') . ' WHERE ' . $q('user_id') . ' = ?', [$user->value], 1)[0] ?? null;
    }

    public function get(EntityId $user): ?array
    {
        $q = $this->sql->quote(...);
        $fields = array_map($q, ['id', 'person_id', 'version', 'token_hash', 'requested_at', 'expires_at', 'state']);
        $substring = in_array($this->sql->driver(), ['sqlite', 'oci'], true) ? 'SUBSTR' : 'SUBSTRING';
        foreach (['sealed_challenge', 'sealed_notice', 'sealed_verification'] as $field) {
            $fields[] = $substring . '(' . $q($field) . ', 1, 8193) AS ' . $q($field);
        }
        $row = $this->sql->fetch('SELECT ' . implode(', ', $fields) . ' FROM ' . $q('sis_email_verifications')
            . ' WHERE ' . $q('id') . ' = ?', [$user->value], 1)[0] ?? null;
        if ($row !== null) {
            $row['version'] = (int) $row['version'];
        }
        return $row;
    }

    public function lock(EntityId $user): void
    {
        // The caller already holds the shared access-state and canonical-person locks for first creation.
        if ($this->get($user) !== null) {
            $this->sql->lock('sis_email_verifications', $user->value, 'version');
        }
    }

    public function request(EntityId $user, int $version, array $row): void
    {
        if ($version === 0) {
            $this->sql->insert('sis_email_verifications', ['id' => $user->value] + $row);
        } elseif (
            $this->sql->update(
                'sis_email_verifications',
                $row,
                ['id' => $user->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function confirm(EntityId $user, int $version, string $proof): void
    {
        if (
            $this->sql->update('sis_email_verifications', ['state' => 'verified', 'sealed_notice' => null,
            'sealed_verification' => $proof], ['id' => $user->value, 'version' => $version]) !== 1
        ) {
            throw new ConcurrentModificationException();
        }
    }

    public function pending(string $now, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_map(static fn (array $row): EntityId => new EntityId($row['id']), $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_email_verifications') . ' WHERE ' . $q('state')
            . ' = ? AND ' . $q('expires_at') . ' > ? ORDER BY ' . $q('expires_at') . ', ' . $q('id'),
            ['queued', $now],
            $limit
        ));
    }

    public function claim(EntityId $user, int $version, string $hash): bool
    {
        return $this->sql->update(
            'sis_email_verifications',
            ['state' => 'sending'],
            ['id' => $user->value, 'version' => $version, 'token_hash' => $hash, 'state' => 'queued']
        ) === 1;
    }

    public function delivered(EntityId $user, int $version, string $hash, string $state): void
    {
        if (!in_array($state, ['sent', 'uncertain', 'expired'], true)) {
            throw new \InvalidArgumentException();
        }
        $this->sql->update(
            'sis_email_verifications',
            ['state' => $state, 'sealed_notice' => null],
            ['id' => $user->value, 'version' => $version, 'token_hash' => $hash, 'state' => 'sending']
        );
    }

    public function expired(string $now, int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_map(static fn (array $row): EntityId => new EntityId($row['id']), $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_email_verifications') . ' WHERE ' . $q('expires_at')
            . ' <= ? AND (' . $q('token_hash') . ' IS NOT NULL OR ' . $q('sealed_notice') . ' IS NOT NULL OR '
            . $q('sealed_challenge') . ' IS NOT NULL) ORDER BY ' . $q('expires_at') . ', ' . $q('id'),
            [$now],
            $limit
        ));
    }

    public function redact(EntityId $user, int $version): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('UPDATE ' . $q('sis_email_verifications') . ' SET ' . $q('token_hash') . ' = NULL, '
            . $q('sealed_notice') . ' = NULL, ' . $q('sealed_challenge') . ' = NULL, ' . $q('state')
            . ' = CASE WHEN ' . $q('state') . " = 'verified' THEN 'verified' ELSE 'expired' END WHERE "
            . $q('id') . ' = ? AND ' . $q('version') . ' = ?', [$user->value, $version]);
    }
}
