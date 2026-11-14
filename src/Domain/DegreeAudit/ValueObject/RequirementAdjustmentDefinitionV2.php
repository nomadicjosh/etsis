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
        if (array_key_exists("component", $definition)) {
            $component = $definition["component"];
            if (!is_array($component)) {
                throw new InvalidArgumentException("Choose a retained specialization assignment.");
            }
            self::keys($component, ["specialization_id", "cohort_binding_revision"]);
            if (
                !is_string($component["specialization_id"]) ||
                !is_int($component["cohort_binding_revision"]) ||
                $component["cohort_binding_revision"] < 0 ||
                $component["cohort_binding_revision"] > 2000000000 ||
                ($definition["kind"] ?? null) === "component_route"
            ) {
                throw new InvalidArgumentException("Choose a retained specialization assignment.");
            }
            new EntityId($component["specialization_id"]);
            unset($definition["component"]);
            new self($definition);
            return;
        }
        if (($definition["kind"] ?? null) === "component_route") {
            self::keys($definition, [
                "kind",
                "course_id",
                "course_version_id",
                "target_template_id",
                "target_version_id",
                "context_hash",
            ]);
            foreach (["course_id", "course_version_id", "target_template_id", "target_version_id"] as $key) {
                if (!is_string($definition[$key])) {
                    throw new InvalidArgumentException("Choose published course and curriculum references.");
                }
                new EntityId($definition[$key]);
            }
            if (
                !is_string($definition["context_hash"]) ||
                !preg_match('/^[a-f0-9]{64}$/D', $definition["context_hash"])
            ) {
                throw new InvalidArgumentException("Review the assigned curriculum before routing work.");
            }
            return;
        }
        if (($definition["kind"] ?? null) === "course_version") {
            self::keys($definition, ["kind", "course_id", "version_id"]);
            foreach (["course_id", "version_id"] as $key) {
                if (!is_string($definition[$key])) {
                    throw new InvalidArgumentException("Choose an explicit course publication.");
                }
                new EntityId($definition[$key]);
            }
            return;
        }
        if (($definition["kind"] ?? null) === "placement") {
            $keys = ["kind", "course_id", "course_version_id", "group_id", "group_version_id"];
            if (array_key_exists("substitution_adjustment_id", $definition)) {
                $keys = [...$keys, "substitution_adjustment_id", "required_course_id"];
            }
            self::keys($definition, $keys);
            foreach (array_diff($keys, ["kind"]) as $key) {
                if (!is_string($definition[$key])) {
                    throw new InvalidArgumentException("Choose a retained course and group publication.");
                }
                new EntityId($definition[$key]);
            }
            if (($definition["required_course_id"] ?? null) === $definition["course_id"]) {
                throw new InvalidArgumentException("A replacement placement needs a different required course.");
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
            throw new InvalidArgumentException(
                "Use a course-publication exception to accept another version of this course.",
            );
        }
    }
    public function key(): string
    {
        if (isset($this->definition["component"])) {
            $definition = $this->definition;
            unset($definition["component"]);
            return new self($definition)->key();
        }
        if ($this->definition["kind"] === "component_route") {
            return "component_route:" . $this->definition["course_id"];
        }
        if ($this->definition["kind"] === "course_version") {
            return "course_version:" . $this->definition["course_id"] . ":" . $this->definition["version_id"];
        }
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
