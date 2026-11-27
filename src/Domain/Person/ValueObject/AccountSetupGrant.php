<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Communications\Service\MessageCipher;
use DomainException;
use Throwable;

/** The existing protected invitation binding, shared by issuance, use and operational retention. */
final class AccountSetupGrant
{
    /** @return array<string,string> */
    public static function claims(#[\SensitiveParameter] array $row): array
    {
        $grant = ['purpose' => 'person.account.setup.v1'];
        $fields = ['id', 'person_id', 'user_id', 'token_hash', 'credential_hash', 'expires_at', 'requested_at'];
        foreach ($fields as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new DomainException('This setup link is unavailable.');
            }
            $grant[$key] = $row[$key];
        }
        return $grant;
    }

    public static function matches(MessageCipher $cipher, #[\SensitiveParameter] array $row): bool
    {
        if (!is_string($row['sealed_grant'] ?? null) || strlen($row['sealed_grant']) > 8192) {
            return false;
        }
        try {
            return json_decode($cipher->open($row['sealed_grant']), true, 4, JSON_THROW_ON_ERROR)
            === self::claims($row);
        } catch (Throwable) {
            return false;
        }
    }
}
