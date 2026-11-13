<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Standalone, program-independent reusable course-group contract. */
final readonly class CourseGroupDefinition
{
    /** @param array<string, mixed> $definition */
    public function __construct(public array $definition)
    {
        self::keys($definition, ["name", "min_credits_milli", "min_gpa_milli", "courses", "rules"]);
        $name = $definition["name"];
        if (
            !is_string($name) ||
            trim($name) === "" ||
            $name !== trim($name) ||
            mb_strlen($name) > 191 ||
            !mb_check_encoding($name, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            self::invalid();
        }
        if (
            !is_int($definition["min_credits_milli"]) ||
            $definition["min_credits_milli"] < 0 ||
            $definition["min_credits_milli"] > 9999999 ||
            ($definition["min_gpa_milli"] !== null &&
                (!is_int($definition["min_gpa_milli"]) ||
                    $definition["min_gpa_milli"] < 0 ||
                    $definition["min_gpa_milli"] > 10000))
        ) {
            self::invalid();
        }
        self::listing($definition["courses"], 200);
        self::listing($definition["rules"], 100);
        $courses = [];
        $used = [];
        $rules = [];
        foreach ($definition["courses"] as $course) {
            self::keys($course, ["course_id", "version_id"]);
            foreach ($course as $id) {
                if (!is_string($id)) {
                    self::invalid();
                }
                new EntityId($id);
            }
            if (isset($courses[$course["course_id"]])) {
                self::invalid();
            }
            $courses[$course["course_id"]] = true;
        }
        foreach ($definition["rules"] as $rule) {
            $keys = ["id", "mode", "minimum_courses", "course_ids"];
            if (($rule["mode"] ?? null) === "credits") {
                $keys[] = "minimum_credits_milli";
            }
            self::keys($rule, $keys);
            if (!is_string($rule["id"])) {
                self::invalid();
            }
            new EntityId($rule["id"]);
            if (isset($rules[$rule["id"]])) {
                self::invalid();
            }
            $rules[$rule["id"]] = true;
            self::listing($rule["course_ids"], 50);
            foreach ($rule["course_ids"] as $id) {
                if (!is_string($id) || !isset($courses[$id])) {
                    self::invalid();
                }
                $used[$id] = true;
            }
            if (
                count(array_unique($rule["course_ids"])) !== count($rule["course_ids"]) ||
                !is_int($rule["minimum_courses"]) ||
                $rule["minimum_courses"] < 0 ||
                $rule["minimum_courses"] > count($rule["course_ids"]) ||
                !in_array($rule["mode"], ["all", "choose", "pool", "credits"], true) ||
                ($rule["mode"] === "all" && $rule["minimum_courses"] !== count($rule["course_ids"])) ||
                ($rule["mode"] === "choose" && $rule["minimum_courses"] < 1) ||
                (in_array($rule["mode"], ["pool", "credits"], true) && $rule["minimum_courses"] !== 0) ||
                ($rule["mode"] === "credits" &&
                    (!is_int($rule["minimum_credits_milli"]) || $rule["minimum_credits_milli"] < 1 ||
                        $rule["minimum_credits_milli"] > 9999999))
            ) {
                self::invalid();
            }
        }
        if (
            array_diff_key($courses, $used) !== [] ||
            strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 49152
        ) {
            self::invalid();
        }
    }
    public static function blank(string $name): self
    {
        return new self([
            "name" => $name,
            "min_credits_milli" => 0,
            "min_gpa_milli" => null,
            "courses" => [],
            "rules" => [],
        ]);
    }
    public function assertPublishable(): void
    {
        if ($this->definition["rules"] === []) {
            throw new InvalidArgumentException("Add a course requirement before publishing this group.");
        }
        foreach ($this->definition["rules"] as $r) {
            if ($r["course_ids"] === []) {
                throw new InvalidArgumentException("Every published requirement needs at least one course.");
            }
        }
        if (
            $this->definition["min_credits_milli"] === 0 &&
            !array_filter($this->definition["rules"], static fn($r) => $r["mode"] !== "pool")
        ) {
            throw new InvalidArgumentException("A credit-pool group needs a positive credit requirement.");
        }
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
    private static function listing(mixed $value, int $max): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Use a bounded course group with valid published courses and all/choose/credit-minimum/pool rules.",
        );
    }
}
