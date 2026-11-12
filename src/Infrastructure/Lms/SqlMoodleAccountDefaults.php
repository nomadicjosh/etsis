<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleAccountDefaults;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleAccountDefaults implements MoodleAccountDefaults
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function forOwner(?EntityId $owner): array
    {
        if ($owner === null) {
            return ["username" => "", "email" => ""];
        }
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch(
            "SELECT " .
                    $q("username") .
                    ", " .
                    $q("email") .
                    " FROM " .
                    $q("users") .
                    " WHERE " .
                    $q("user_id") .
                    " = ?",
            [$owner->value],
            1,
        )[0] ?? [];
        return [
            "username" => is_string($row["username"] ?? null) ? $row["username"] : "",
            "email" => is_string($row["email"] ?? null) ? $row["email"] : "",
        ];
    }
}
