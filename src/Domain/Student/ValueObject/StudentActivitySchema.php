<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentActivityWasObserved;
use Domain\Student\Event\StudentActivityWasDecided;

/** Frozen v1 reader; current command policy must not change accepted historical events. */
final class StudentActivitySchema
{
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $observed = $class === StudentActivityWasObserved::class;
        $keys = $observed
        ? ["decision_id", "student_id", "person_id", "active", "effective_on"]
        : [
                "decision_id",
                "previous_decision_id",
                "student_id",
                "active",
                "effective_on",
                "source",
                "reference_id",
            ];
        if (
            (!$observed && $class !== StudentActivityWasDecided::class) ||
            count($p) !== count($keys) ||
            array_diff(array_keys($p), $keys) !== [] ||
            !is_bool($p["active"] ?? null) ||
            !is_string($p["effective_on"] ?? null) ||
            !self::date($p["effective_on"])
        ) {
            throw new CorruptHistoryException();
        }
        $ids = $observed
        ? ["decision_id", "student_id", "person_id"]
        : ["decision_id", "previous_decision_id", "student_id"];
        foreach ($ids as $key) {
            if (!is_string($p[$key] ?? null)) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        if (!$observed) {
            if (
                !in_array($p["source"] ?? null, ["registrar", "hiatus", "hiatus_return"], true) ||
                ($p["source"] === "registrar"
                    ? $p["reference_id"] !== null
                    : !is_string($p["reference_id"])) ||
                $p["decision_id"] === $p["previous_decision_id"]
            ) {
                throw new CorruptHistoryException();
            }
            if ($p["reference_id"] !== null) {
                new EntityId($p["reference_id"]);
            }
        }
    }
    public static function date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
        return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1 &&
        $date !== false &&
        $date->format("Y-m-d") === $value &&
        substr($value, 0, 4) !== "0000";
    }
}
