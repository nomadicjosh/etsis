<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Degree composition owns version references, never editable embedded course groups. */
final readonly class AuditRequirementsV3
{
    public AuditRequirementsV2 $settings;
    public CourseGroupReuse $groupReuse;
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        $reuse = $definition["policies"]["group_reuse"] ?? null;
        if (!is_string($reuse) || ($policy = CourseGroupReuse::tryFrom($reuse)) === null) {
            throw new InvalidArgumentException(
                "Choose whether completed courses can satisfy one group or multiple groups.",
            );
        }
        $this->groupReuse = $policy;
        $base = $definition;
        unset($base["policies"]["group_reuse"]);
        $base["courses"] = [];
        $base["groups"] = [];
        $this->settings = new AuditRequirementsV2($base);
        if (
            !is_array($definition["groups"] ?? null) ||
            !array_is_list($definition["groups"]) ||
            count($definition["groups"]) > 25
        ) {
            self::invalid();
        }
        $used = [];
        foreach ($definition["groups"] as $group) {
            if (
                !is_array($group) ||
                count($group) !== 2 ||
                array_diff(array_keys($group), ["group_id", "version_id"]) !== [] ||
                !is_string($group["group_id"]) ||
                ($group["version_id"] !== null && !is_string($group["version_id"]))
            ) {
                self::invalid();
            }
            new EntityId($group["group_id"]);
            if ($group["version_id"] !== null) {
                new EntityId($group["version_id"]);
            }
            if (isset($used[$group["group_id"]])) {
                self::invalid();
            }
            $used[$group["group_id"]] = true;
        }
        // The legacy 'courses' key and any additional embedded configuration are deliberately forbidden.
        if (
            array_key_exists("courses", $definition) ||
            strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 49152
        ) {
            self::invalid();
        }
    }
    public static function blank(string $name): self
    {
        $d = AuditRequirementsV2::blank($name)->definition;
        unset($d["courses"]);
        $d["policies"]["group_reuse"] = CourseGroupReuse::OneGroup->value;
        return new self($d);
    }
    /** @param array<string, mixed> $definition */
    public static function draft(array $definition): AuditRequirements|AuditRequirementsV2|self
    {
        return array_key_exists("courses", $definition)
        ? AuditRequirementsV2::draft($definition)
        : new self($definition);
    }
    public function assertPublishable(): void
    {
        if ($this->definition["min_credits_milli"] < 1 || $this->definition["groups"] === []) {
            self::invalid();
        }
        foreach ($this->definition["groups"] as $g) {
            if ($g["version_id"] === null) {
                throw new InvalidArgumentException(
                    "Choose a published version for every course group before publishing this degree.",
                );
            }
        }
        foreach (["repeat_credit", "repeat_gpa", "transfer_overall_gpa"] as $key) {
            if ($this->definition["policies"][$key] === null) {
                throw new InvalidArgumentException(
                    "Choose the institutional audit policies before publication.",
                );
            }
        }
    }
    public function hash(): string
    {
        return hash("sha256", json_encode($this->definition, JSON_THROW_ON_ERROR));
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Compose a degree from distinct reusable course groups and valid general requirements.",
        );
    }
}
