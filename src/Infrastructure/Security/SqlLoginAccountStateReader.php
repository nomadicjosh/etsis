<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Domain\User\Repository\LoginAccountStateReader;
use Domain\User\ValueObject\LoginAccountState;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlLoginAccountStateReader implements LoginAccountStateReader
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function current(EntityId $user): ?LoginAccountState
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " .
                $q("token") .
                ", " .
                $q("password") .
                " FROM " .
                $q("users") .
                " WHERE " .
                $q("user_id") .
                " = ?",
            [$user->value],
            1,
        );
        $row = $rows[0] ?? null;
        if (
            $row === null ||
            !is_string($row["token"]) ||
            $row["token"] === "" ||
            !is_string($row["password"]) ||
            $row["password"] === ""
        ) {
            return null;
        }
        return new LoginAccountState(
            hash("sha256", $row["token"]),
            hash("sha256", json_encode([$row["token"], $row["password"]], JSON_THROW_ON_ERROR)),
        );
    }
}
