<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** An explicitly recorded pursuit date; never inferred from today's program or term. */
final readonly class PursuitStart
{
    public function __construct(public string $value)
    {
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("UTC"));
        if ($date === false || $date->format("Y-m-d") !== $value) {
            throw new InvalidArgumentException("Provide a valid program pursuit start date.");
        }
    }

    /**
     * @throws DateMalformedStringException
     */
    public function anticipatedCompletion(?int $months = null): ?string
    {
        if ($months === null) {
            return null;
        }
        if ($months < 1 || $months > 240) {
            throw new InvalidArgumentException("Provide a bounded program length.");
        }
        $first = new DateTimeImmutable(substr($this->value, 0, 7) . "-01", new DateTimeZone("UTC"))->modify(
            "+" . $months . " months",
        );
        return $first->format("Y-m-") .
        str_pad(
            (string) min((int) substr($this->value, 8), (int) $first->format("t")),
            2,
            "0",
            STR_PAD_LEFT,
        );
    }
}
