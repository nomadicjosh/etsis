<?php

declare(strict_types=1);

namespace Domain\Tuition;

final class TuitionSchedule extends \Codefy\Domain\Aggregate\EventSourcedAggregate
{
    public const array FIELDS = ["definition", "operation_id", "intent"];
    public static function validate(array $p): void
    {
        \Domain\Finance\ValueObject\LedgerSchema::keys($p, self::FIELDS);
        new \Domain\Shared\ValueObject\EntityId($p["operation_id"]);
        \Domain\Tuition\ValueObject\TuitionSchema::definition($p["definition"]);
        if (
            !is_string($p["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["intent"]) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Retain the exact tuition decision.",
            );
        }
    }
}
