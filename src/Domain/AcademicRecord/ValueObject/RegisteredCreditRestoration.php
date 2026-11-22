<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Recorded registration quantity retained during reinstatement, separate from the award. */
final readonly class RegisteredCreditRestoration
{
    public array $facts;

    public function __construct(array $facts)
    {
        if (
            count($facts) !== 3 ||
            array_diff(array_keys($facts), [
                "original_milli",
                "registered_milli",
                "correction_count",
            ]) !== [] ||
            !is_int($facts["original_milli"] ?? null) ||
            !is_int($facts["registered_milli"] ?? null) ||
            !is_int($facts["correction_count"] ?? null) ||
            $facts["original_milli"] < 0 ||
            $facts["original_milli"] > 2147483646 ||
            $facts["registered_milli"] < 0 ||
            $facts["registered_milli"] > 999999 ||
            $facts["correction_count"] < 1 ||
            $facts["correction_count"] > 2147483646
        ) {
            throw new CorruptHistoryException();
        }
        $this->facts = [
            "original_milli" => $facts["original_milli"],
            "registered_milli" => $facts["registered_milli"],
            "correction_count" => $facts["correction_count"],
        ];
    }
}
