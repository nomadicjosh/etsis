<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;

final readonly class HiatusFacts
{
    public const array FIELDS = [
        "id",
        "student_id",
        "code",
        "starts_on",
        "ends_on",
        "voided",
        "version",
    ];

    /** @param array<string,mixed> $data */
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        self::keys($data, self::FIELDS);
        foreach (["id", "student_id"] as $key) {
            if (!is_string($data[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($data[$key]);
        }
        if (
            !is_string($data["code"]) ||
            HiatusCode::tryFrom($data["code"]) === null ||
            !is_bool($data["voided"]) ||
            !is_int($data["version"]) ||
            $data["version"] < 0 ||
            $data["version"] > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
        self::date($data["starts_on"]);
        if ($data["ends_on"] !== null) {
            self::date($data["ends_on"]);
            if ($data["ends_on"] < $data["starts_on"]) {
                throw new CorruptHistoryException();
            }
        }
    }

    public static function fromHiatus(StudentHiatus $hiatus): self
    {
        return new self([
            "id" => $hiatus->id->value,
            "student_id" => $hiatus->studentId->value,
            "code" => $hiatus->code->value,
            "starts_on" => $hiatus->dates->start->format("Y-m-d"),
            "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
            "voided" => $hiatus->voided,
            "version" => $hiatus->version,
        ]);
    }

    public static function date(mixed $date): void
    {
        if (
            !is_string($date) ||
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1 ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    public static function keys(array $data, array $keys): void
    {
        if (
            count($data) !== count($keys) ||
            array_diff(array_keys($data), $keys) !== []
        ) {
            throw new CorruptHistoryException();
        }
    }
}
