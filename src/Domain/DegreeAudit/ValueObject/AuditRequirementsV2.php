<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;

/** Requirements v2 retains explicit institution-selected calculation policies. */
final readonly class AuditRequirementsV2
{
    public AuditRequirements $core;
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        $base = $definition;
        unset($base["policies"]);
        $this->core = new AuditRequirements($base);
        $policy = $definition["policies"] ?? null;
        if (
            !is_array($policy) ||
            count($policy) !== 4 ||
            array_diff(array_keys($policy), [
                "repeat_credit",
                "repeat_gpa",
                "transfer_overall_gpa",
                "waiver",
            ]) !== []
        ) {
            throw new InvalidArgumentException("Provide explicit degree-audit policy settings.");
        }
        $supported = [
            "repeat_credit" => ["highest_earned", "all_eligible"],
            "repeat_gpa" => ["all_eligible", "highest_grade", "latest_eligible"],
            "transfer_overall_gpa" => ["exclude", "include_eligible"],
        ];
        foreach ($supported as $key => $choices) {
            if ($policy[$key] !== null && !in_array($policy[$key], $choices, true)) {
                throw new InvalidArgumentException("Choose a supported institutional audit policy.");
            }
        }
        if (
            $policy["waiver"] !== "waive_required_course_everywhere" ||
            strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 49152
        ) {
            throw new InvalidArgumentException(
                "Waivers remove course requirements everywhere without creating earned credit or GPA.",
            );
        }
    }
    /** Draft schemas may be identified by their shape; journal schemas always use the registered version.
     * @param array<string, mixed> $definition
     */
    public static function draft(array $definition): AuditRequirements|self
    {
        if (array_key_exists("policies", $definition)) {
            return new self($definition);
        }
        return new AuditRequirements($definition);
    }
    public static function blank(string $name): self
    {
        return new self(
            AuditRequirements::blank($name)->definition + [
                "policies" => [
                    "repeat_credit" => null,
                    "repeat_gpa" => null,
                    "transfer_overall_gpa" => null,
                    "waiver" => "waive_required_course_everywhere",
                ],
            ],
        );
    }
    public function assertPublishable(): void
    {
        $this->core->assertPublishable();
        foreach (["repeat_credit", "repeat_gpa", "transfer_overall_gpa"] as $key) {
            if ($this->definition["policies"][$key] === null) {
                throw new InvalidArgumentException(
                    "Choose repeat-credit, repeat-GPA and transfer-GPA policies before publishing.",
                );
            }
        }
    }
    public function hash(): string
    {
        return hash("sha256", json_encode($this->definition, JSON_THROW_ON_ERROR));
    }
}
