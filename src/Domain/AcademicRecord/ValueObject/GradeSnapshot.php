<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Gradebook\GradeDefinition;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class GradeSnapshot
{
    public const array FIELDS = [
        "id",
        "code",
        "points_milli",
        "passes",
        "counts_gpa",
        "scale_id",
        "scale_version_id",
    ];
    /** @param array<string,mixed> $facts */
    public function __construct(public array $facts)
    {
        if (
            count($facts) !== 7 ||
            array_diff(array_keys($facts), self::FIELDS) !== [] ||
            !is_string($facts["id"]) ||
            !is_string($facts["code"]) ||
            !is_int($facts["points_milli"]) ||
            !is_bool($facts["passes"]) ||
            !is_bool($facts["counts_gpa"]) ||
            ($facts["scale_id"] === null) !== ($facts["scale_version_id"] === null)
        ) {
            throw new InvalidArgumentException("Use a retained grade definition.");
        }
        new GradeDefinition(
            new EntityId($facts["id"]),
            $facts["code"],
            $facts["points_milli"],
            $facts["passes"],
            $facts["counts_gpa"],
        );
        foreach (["scale_id", "scale_version_id"] as $key) {
            if ($facts[$key] !== null) {
                if (!is_string($facts[$key])) {
                    throw new InvalidArgumentException("Invalid grade publication.");
                }
                new EntityId($facts[$key]);
            }
        }
    }
    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self([
            "id" => $row["id"],
            "code" => $row["code"],
            "points_milli" => (int) $row["points_milli"],
            "passes" => (bool) $row["passes"],
            "counts_gpa" => (bool) $row["counts_gpa"],
            "scale_id" => $row["scale_id"] ?? null,
            "scale_version_id" => $row["scale_version_id"] ?? null,
        ]);
    }
}
