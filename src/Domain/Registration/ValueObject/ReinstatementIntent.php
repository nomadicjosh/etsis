<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;

final class ReinstatementIntent
{
    public static function hash(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [
                    "withdrawal_reinstatement",
                    $student->value,
                    $section->value,
                    $enrollment->value,
                    $operation->value,
                    $revision,
                    $reason,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    public static function revision(#[\SensitiveParameter] array $facts): string
    {
        return hash("sha256", json_encode($facts, JSON_THROW_ON_ERROR));
    }
}
