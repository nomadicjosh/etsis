<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Published group restrictions on recorded origin and actual completion, never posting dates. */
final readonly class GroupWorkEligibility
{
    public const array DEFAULT = [
        "credit_origin" => "any",
        "completed_from" => null,
        "completed_through" => null,
    ];

    public function __construct(public array $facts = self::DEFAULT)
    {
        if (
            count($facts) !== 3 ||
            array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== [] ||
            !in_array($facts["credit_origin"] ?? null, ["any", "institutional", "transfer"], true)
        ) {
            self::invalid();
        }
        foreach (["completed_from", "completed_through"] as $key) {
            if ($facts[$key] !== null) {
                self::date($facts[$key]);
            }
        }
        if (
            $facts["completed_from"] !== null &&
            $facts["completed_through"] !== null &&
            $facts["completed_from"] > $facts["completed_through"]
        ) {
            self::invalid();
        }
    }

    /** Origin can establish exclusion without guessing other missing academic facts. */
    public function originStatus(?string $origin = null): ?CourseMatchStatus
    {
        if ($this->facts["credit_origin"] === "any") {
            return null;
        }
        if (!in_array($origin, ["institutional", "transfer"], true)) {
            return CourseMatchStatus::OriginUnknown;
        }
        return $origin === $this->facts["credit_origin"] ? null : CourseMatchStatus::OriginExcluded;
    }

    public function completionStatus(?string $completedOn = null): ?CourseMatchStatus
    {
        if ($this->facts["completed_from"] === null && $this->facts["completed_through"] === null) {
            return null;
        }
        if ($completedOn === null) {
            return CourseMatchStatus::CompletionDateUnknown;
        }
        self::date($completedOn);
        if (
            $this->facts["completed_from"] !== null &&
            $completedOn < $this->facts["completed_from"]
        ) {
            return CourseMatchStatus::CompletedBeforeWindow;
        }
        if (
            $this->facts["completed_through"] !== null &&
            $completedOn > $this->facts["completed_through"]
        ) {
            return CourseMatchStatus::CompletedAfterWindow;
        }
        return null;
    }

    private static function date(mixed $value): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 ||
            substr($value, 0, 4) === "0000"
        ) {
            self::invalid();
        }
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("UTC"));
        if ($date === false || $date->format("Y-m-d") !== $value) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Choose a credit origin and valid inclusive completion dates in order.",
        );
    }
}
