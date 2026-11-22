<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;

/** Inclusive whole UTC second of recorded account activity, not a due date or an effective-date policy. */
final readonly class ReportFinancialCutoff
{
    public string $sql;

    public function __construct(mixed $value)
    {
        $date =
        is_string($value) &&
        preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) === 1
        ? DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s\Z",
            $value,
            new DateTimeZone("UTC"),
        )
        : false;
        if (
            $date === false ||
            $date->format("Y-m-d\TH:i:s\Z") !== $value ||
            substr($value, 0, 4) === "0000"
        ) {
            throw new ValidationException([
                [
                    "field" => "financial_as_of",
                    "message" =>
                        "Choose a real UTC cutoff with whole seconds for this historical financial report.",
                ],
            ]);
        }
        $this->sql = $date->format("Y-m-d H:i:s");
    }
    public function requireRecordedBy(\DateTimeImmutable $now): void
    {
        if ($this->sql > $now->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s")) {
            throw new ValidationException([
                [
                    "field" => "financial_as_of",
                    "message" => "Choose a cutoff no later than the current UTC second.",
                ],
            ]);
        }
    }
}
