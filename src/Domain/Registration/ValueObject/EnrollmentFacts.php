<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Registration\Enrollment;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen version-1 enrollment projection; SQL timestamps retain their existing UTC second precision. */
final readonly class EnrollmentFacts
{
    public const array FIELDS = [
        "id",
        "student_id",
        "section_id",
        "term_id",
        "status",
        "registered_at",
        "status_at",
        "version",
    ];
    /** @param array<string,mixed> $facts */
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        if (count($facts) !== 8 || array_diff(array_keys($facts), self::FIELDS) !== []) {
            self::invalid();
        }
        foreach (["id", "student_id", "section_id", "term_id"] as $key) {
            if (!is_string($facts[$key])) {
                self::invalid();
            }
            new EntityId($facts[$key]);
        }
        if (
            !in_array($facts["status"], ["A", "N", "D", "W"], true) ||
            !is_int($facts["version"]) ||
            $facts["version"] < 0 ||
            $facts["version"] > 2147483646
        ) {
            self::invalid();
        }
        self::timestamp($facts["registered_at"]);
        self::timestamp($facts["status_at"]);
    }
    public static function fromEnrollment(Enrollment $e): self
    {
        return new self([
            "id" => $e->id->value,
            "student_id" => $e->studentId->value,
            "section_id" => $e->sectionId->value,
            "term_id" => $e->termId->value,
            "status" => $e->status->value,
            "registered_at" => self::utc($e->registeredAt),
            "status_at" => self::utc($e->statusAt),
            "version" => $e->version,
        ]);
    }
    public static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");
    }
    public static function timestamp(mixed $value): void
    {
        if (
            !is_string($value) ||
            !($date = DateTimeImmutable::createFromFormat("!Y-m-d H:i:s", $value, new DateTimeZone("UTC"))) ||
            $date->format("Y-m-d H:i:s") !== $value
        ) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid retained enrollment facts.");
    }
}
