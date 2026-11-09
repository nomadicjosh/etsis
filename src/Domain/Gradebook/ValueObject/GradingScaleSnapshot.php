<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class GradingScaleSnapshot
{
    /** @param array<string,mixed> $data */
    public function __construct(public array $data)
    {
        if (
            count($data) !== 6 ||
            array_diff(array_keys($data), [
                "source",
                "program_id",
                "version_id",
                "maximum_milli",
                "is_default",
                "definition",
            ]) !== [] ||
            !in_array($data["source"], ["default", "global", "program"], true) ||
            !is_bool($data["is_default"]) ||
            !is_array($data["definition"])
        ) {
            self::invalid();
        }
        $d = new GradingScaleDefinition($data["definition"]);
        if (
            $data["maximum_milli"] !== $d->maximum() ||
            $data["is_default"] !== ($data["source"] === "default") ||
            ($data["source"] === "default") !== ($d->definition["grades"] === [])
        ) {
            self::invalid();
        }
        if ($data["source"] === "program") {
            if (!is_string($data["program_id"]) || !is_string($data["version_id"])) {
                self::invalid();
            }
            new EntityId($data["program_id"]);
            new EntityId($data["version_id"]);
        } elseif ($data["program_id"] !== null) {
            self::invalid();
        } elseif ($data["version_id"] !== null) {
            if (!is_string($data["version_id"]) || $data["source"] !== "global") {
                self::invalid();
            }
            new EntityId($data["version_id"]);
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid retained grading scale.");
    }
}
