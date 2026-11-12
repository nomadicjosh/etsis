<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\AccountIdentityLookup;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAccountIdentityLookup implements AccountIdentityLookup
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function forEmail(#[\SensitiveParameter] string $email): ?EntityId
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " . $q("user_id") . " FROM " . $q("users") . " WHERE " . $q("email") . " = ?",
            [$email],
            2,
        );
        return count($rows) === 1 ? new EntityId($rows[0]["user_id"]) : null;
    }
}
