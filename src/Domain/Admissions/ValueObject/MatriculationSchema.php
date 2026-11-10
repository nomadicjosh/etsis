<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\Event\StudentWasMatriculated;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen v1; the accepted application is an observed prerequisite, not invented acceptance history. */
final class MatriculationSchema
{
    public const array FIELDS = [
        "decision_id",
        "application_id",
        "person_id",
        "program_id",
        "entry_term_id",
        "student_id",
        "assignment_id",
        "accepted_version",
    ];
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        if (
            $class !== StudentWasMatriculated::class ||
            count($p) !== count(self::FIELDS) ||
            array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_int($p["accepted_version"] ?? null) ||
            $p["accepted_version"] < 1
        ) {
            throw new CorruptHistoryException();
        }
        foreach (self::FIELDS as $key) {
            if ($key === "accepted_version") {
                continue;
            }
            if (!is_string($p[$key] ?? null)) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
    }
}
