<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen v1 authoring grammar: AND between rows; all/choose/credit-pool within each row. */
final readonly class AuditRequirements
{
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        self::keys($definition, [
            "name",
            "length_months",
            "min_credits_milli",
            "min_resident_milli",
            "min_cumulative_gpa_milli",
            "min_overall_gpa_milli",
            "courses",
            "groups",
        ]);
        self::label($definition["name"]);
        self::number($definition["length_months"], 1, 240, true);
        self::number($definition["min_credits_milli"], 0, 9999999);
        self::number($definition["min_resident_milli"], 0, $definition["min_credits_milli"]);
        self::number($definition["min_cumulative_gpa_milli"], 0, 10000, true);
        self::number($definition["min_overall_gpa_milli"], 0, 10000, true);
        self::listing($definition["courses"], 200);
        self::listing($definition["groups"], 25);
        $courses = [];
        foreach ($definition["courses"] as $course) {
            self::keys($course, ["course_id", "version_id"]);
            if (!is_string($course["course_id"]) || !is_string($course["version_id"])) {
                self::invalid();
            }
            $id = new EntityId($course["course_id"]);
            new EntityId($course["version_id"]);
            if (isset($courses[$id->value])) {
                self::invalid();
            }
            $courses[$id->value] = true;
        }
        $identities = [];
        $used = [];
        $ruleCount = 0;
        foreach ($definition["groups"] as $group) {
            self::keys($group, ["id", "name", "min_credits_milli", "min_gpa_milli", "rules"]);
            self::identity($group["id"], $identities);
            self::label($group["name"]);
            self::number($group["min_credits_milli"], 0, 9999999);
            self::number($group["min_gpa_milli"], 0, 10000, true);
            self::listing($group["rules"], 100);
            foreach ($group["rules"] as $rule) {
                if (++$ruleCount > 100) {
                    self::invalid();
                }
                self::keys($rule, ["id", "mode", "minimum_courses", "course_ids"]);
                self::identity($rule["id"], $identities);
                self::listing($rule["course_ids"], 50);
                foreach ($rule["course_ids"] as $candidate) {
                    if (!is_string($candidate)) {
                        self::invalid();
                    }
                }
                if (count(array_unique($rule["course_ids"])) !== count($rule["course_ids"])) {
                    self::invalid();
                }
                self::number($rule["minimum_courses"], 0, count($rule["course_ids"]));
                if (
                    !in_array($rule["mode"], ["all", "choose", "pool"], true) ||
                    ($rule["mode"] === "all" && $rule["minimum_courses"] !== count($rule["course_ids"])) ||
                    ($rule["mode"] === "choose" && $rule["minimum_courses"] < 1) ||
                    ($rule["mode"] === "pool" && $rule["minimum_courses"] !== 0)
                ) {
                    self::invalid();
                }
                foreach ($rule["course_ids"] as $course) {
                    if (!is_string($course) || !isset($courses[$course])) {
                        self::invalid();
                    }
                    $used[$course] = true;
                }
            }
        }
        // No hidden, unrelated references retained in a definition.
        if (
            array_diff_key($courses, $used) !== [] ||
            strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 49152
        ) {
            self::invalid();
        }
    }

    public function assertPublishable(): void
    {
        if ($this->definition["min_credits_milli"] < 1 || $this->definition["groups"] === []) {
            throw new InvalidArgumentException(
                "Set total credits and add at least one course group before publication.",
            );
        }
        foreach ($this->definition["groups"] as $group) {
            foreach ($group["rules"] as $rule) {
                if ($rule["course_ids"] === []) {
                    throw new InvalidArgumentException("Every published rule needs at least one course.");
                }
            }
            if ($group["rules"] === []) {
                throw new InvalidArgumentException("Each published group needs at least one course rule.");
            }
            if (
                $group["min_credits_milli"] === 0 &&
                !array_filter($group["rules"], static fn($r) => $r["mode"] !== "pool")
            ) {
                throw new InvalidArgumentException(
                    "A credit-pool group needs a positive credit requirement.",
                );
            }
        }
    }

    public static function blank(string $name): self
    {
        return new self([
            "name" => $name,
            "length_months" => null,
            "min_credits_milli" => 0,
            "min_resident_milli" => 0,
            "min_cumulative_gpa_milli" => null,
            "min_overall_gpa_milli" => null,
            "courses" => [],
            "groups" => [],
        ]);
    }

    public function hash(): string
    {
        return hash("sha256", json_encode($this->definition, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $keys */
    private static function keys(mixed $value, array $keys): void
    {
        if (
            !is_array($value) ||
            count($value) !== count($keys) ||
            array_diff(array_keys($value), $keys) !== []
        ) {
            self::invalid();
        }
    }
    private static function label(mixed $value): void
    {
        if (
            !is_string($value) ||
            trim($value) === "" ||
            $value !== trim($value) ||
            mb_strlen($value) > 191 ||
            !mb_check_encoding($value, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            self::invalid();
        }
    }
    private static function number(mixed $value, int $min, int $max, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            self::invalid();
        }
    }
    private static function listing(mixed $value, int $max): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            self::invalid();
        }
    }
    /** @param array<string, bool> $used */
    private static function identity(mixed $id, array &$used): void
    {
        if (!is_string($id)) {
            self::invalid();
        }
        new EntityId($id);
        if (isset($used[$id])) {
            self::invalid();
        }
        $used[$id] = true;
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Use a valid, bounded degree requirement definition.");
    }
}
