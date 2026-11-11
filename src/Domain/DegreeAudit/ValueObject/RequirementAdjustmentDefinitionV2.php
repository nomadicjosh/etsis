<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Current approval grammar: substitutions and placement preferences extend minimums and waivers. */
final readonly class RequirementAdjustmentDefinitionV2
{
    public const array DEGREE_MINIMUMS = RequirementAdjustmentDefinition::DEGREE_MINIMUMS;
    public const array GROUP_MINIMUMS = RequirementAdjustmentDefinition::GROUP_MINIMUMS;
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        if (($definition["kind"] ?? null) === "placement") {
            self::keys($definition, [
                "kind",
                "course_id",
                "course_version_id",
                "group_id",
                "group_version_id",
            ]);
            foreach (["course_id", "course_version_id", "group_id", "group_version_id"] as $key) {
                if (!is_string($definition[$key])) {
                    throw new InvalidArgumentException("Choose a retained course and group publication.");
                }
                new EntityId($definition[$key]);
            }
            return;
        }
        if (($definition["kind"] ?? null) !== "substitution") {
            new RequirementAdjustmentDefinition($definition);
            return;
        }
        self::keys($definition, ["kind", "course_id", "replacement_course_id", "replacement_version_id"]);
        foreach (["course_id", "replacement_course_id", "replacement_version_id"] as $key) {
            if (!is_string($definition[$key])) {
                throw new InvalidArgumentException("Choose explicit published course references.");
            }
            new EntityId($definition[$key]);
        }
        if ($definition["course_id"] === $definition["replacement_course_id"]) {
            throw new InvalidArgumentException("Choose a different course as the replacement.");
        }
    }
    public function key(): string
    {
        if ($this->definition["kind"] === "placement") {
            return "placement:" . $this->definition["course_id"];
        }
        return $this->definition["kind"] === "substitution"
        ? "substitution:" . $this->definition["course_id"]
        : new RequirementAdjustmentDefinition($this->definition)->key();
    }
    /** @param array<string, mixed> $value
     * @param list<string> $keys */
    public static function keys(array $value, array $keys): void
    {
        RequirementAdjustmentDefinition::keys($value, $keys);
    }
}
