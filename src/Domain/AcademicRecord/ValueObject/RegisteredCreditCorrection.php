<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** A current correction of recorded registration quantity, separate from an academic award or price. */
final class RegisteredCreditCorrection
{
    public const string PERMISSION = "sis:registration:credits:amend";
    public const array FIELDS = [
        "decision_id",
        "previous_decision_id",
        "previous_registered_milli",
        "registered_milli",
    ];
    public static function validate(array $p): void
    {
        if (
            count($p) !== 4 ||
            array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_string($p["decision_id"] ?? null) ||
            !is_string($p["previous_decision_id"] ?? null) ||
            !is_int($p["previous_registered_milli"] ?? null) ||
            !is_int($p["registered_milli"] ?? null) ||
            $p["previous_registered_milli"] < 0 ||
            $p["previous_registered_milli"] > 2147483646 ||
            $p["registered_milli"] < 0 ||
            $p["registered_milli"] > 999999 ||
            $p["previous_registered_milli"] === $p["registered_milli"]
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p["decision_id"]);
        new EntityId($p["previous_decision_id"]);
        if ($p["decision_id"] === $p["previous_decision_id"]) {
            throw new CorruptHistoryException();
        }
    }
}
