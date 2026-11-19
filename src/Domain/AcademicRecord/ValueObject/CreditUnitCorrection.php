<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;

final readonly class CreditUnitCorrection
{
    public const string PERMISSION = "sis:academic:credits:amend";

    public function __construct(public int $previousMilli, public int $attemptedMilli)
    {
        if (
            $previousMilli < 0 ||
            $previousMilli > 2147483646 ||
            $attemptedMilli < 0 ||
            $attemptedMilli > 999999 ||
            $previousMilli === $attemptedMilli
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function fromArray(array $facts): self
    {
        if (
            count($facts) !== 2 ||
            array_diff(array_keys($facts), ["previous_attempted_milli", "attempted_milli"]) !== [] ||
            !is_int($facts["previous_attempted_milli"] ?? null) ||
            !is_int($facts["attempted_milli"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        return new self($facts["previous_attempted_milli"], $facts["attempted_milli"]);
    }

    public function facts(): array
    {
        return [
            "previous_attempted_milli" => $this->previousMilli,
            "attempted_milli" => $this->attemptedMilli,
        ];
    }

    public function summary(array $credit, array $grade): array
    {
        return [
            "previous_attempted_milli" => $credit["attempted_milli"],
            "previous_completed_milli" => $credit["completed_milli"],
            "previous_grade_points_milli" => $credit["grade_points_milli"],
        ] + new GradeSnapshot($grade)->quantities($this->attemptedMilli, $credit["status"]);
    }
}
