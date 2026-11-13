<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use JsonException;

/**
 * Normalized structural constraints, with eligible awards already selected by retained policies.
 * @phpstan-type AllocationRule array{
 *     id:string,mode:string,minimum_courses:int,course_ids:list<string>,minimum_credits_milli?:int
 * }
 * @phpstan-type AllocationGroup array{id:string,min_credits_milli:int,min_courses:int,rules:list<AllocationRule>}
 * @phpstan-type AllocationChoice array{
 *     group_id:string,target_course_id:string,credits_milli:int,work_ids:list<string>,
 *     rule_credits_milli?:array<string,int>
 * }
 * @phpstan-type AllocationCourse array{course_id:string,choices:list<AllocationChoice>}
 */
final readonly class AllocationProblem
{
    /** @var list<AllocationGroup> */
    public array $groups;
    /** @var list<AllocationCourse> */
    public array $courses;

    /** @var list<string> */
    public array $waivedCourseIds;

    /** @var array<string,array<string,array<string,true>>> Rule memberships by group and target. */
    private array $memberships;

    /**
     * @param list<AllocationGroup> $groups
     * @param list<AllocationCourse> $courses
     * @param list<string> $waivedCourseIds Pursuit-wide approvals; absent courses add no requirements.
     * @throws JsonException
     */
    public function __construct(
        array $groups,
        array $courses,
        public CourseGroupReuse $reuse,
        array $waivedCourseIds = [],
    ) {
        self::listing($waivedCourseIds, 5000);
        if (count(array_unique($waivedCourseIds)) !== count($waivedCourseIds)) {
            self::invalid();
        }
        foreach ($waivedCourseIds as $id) {
            self::id($id);
        }
        sort($waivedCourseIds, SORT_STRING);
        $this->waivedCourseIds = $waivedCourseIds;
        self::listing($groups, 25);
        self::listing($courses, 200);
        if ($groups === []) {
            self::invalid();
        }
        $targets = [];
        $memberships = [];
        foreach ($groups as &$group) {
            self::keys($group, ["id", "min_credits_milli", "min_courses", "rules"]);
            self::listing($group["rules"], 100);
            self::id($group["id"]);
            self::quantity($group["min_credits_milli"], 9999999);
            self::quantity($group["min_courses"], 200);
            if (isset($targets[$group["id"]])) {
                self::invalid();
            }
            $targets[$group["id"]] = [];
            $ruleIds = [];
            foreach ($group["rules"] as &$rule) {
                $keys = ["id", "mode", "minimum_courses", "course_ids"];
                if (array_key_exists("minimum_credits_milli", $rule)) {
                    $keys[] = "minimum_credits_milli";
                }
                self::keys($rule, $keys);
                self::listing($rule["course_ids"], 200);
                self::id($rule["id"]);
                if (
                    isset($ruleIds[$rule["id"]]) ||
                    !in_array($rule["mode"], ["all", "choose", "pool", "credits"], true) ||
                    count(array_unique($rule["course_ids"])) !== count($rule["course_ids"])
                ) {
                    self::invalid();
                }
                self::quantity($rule["minimum_courses"], 200);
                if (
                    ($rule["mode"] === "all" && $rule["minimum_courses"] !== count($rule["course_ids"])) ||
                    ($rule["mode"] === "choose" && $rule["minimum_courses"] < 1) ||
                    (in_array($rule["mode"], ["pool", "credits"], true) && $rule["minimum_courses"] !== 0) ||
                    ($rule["mode"] !== "credits" && array_key_exists("minimum_credits_milli", $rule))
                ) {
                    self::invalid();
                }
                if ($rule["mode"] === "credits") {
                    $minimum = $rule["minimum_credits_milli"] ?? 0;
                    self::quantity($minimum, 9999999);
                    if ($minimum < 1) {
                        self::invalid();
                    }
                }
                $ruleIds[$rule["id"]] = true;
                foreach ($rule["course_ids"] as $id) {
                    self::id($id);
                    $targets[$group["id"]][$id] = true;
                    $memberships[$group['id']][$id][$rule['id']] = true;
                }
                sort($rule["course_ids"], SORT_STRING);
            }
            unset($rule);
            if (count($targets[$group["id"]]) > 200) {
                self::invalid();
            }
            usort($group["rules"], static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        }
        unset($group);
        $courseIds = [];
        $owners = [];
        $choiceCount = 0;
        $evidenceCount = 0;
        $amounts = [];
        $contributionCount = 0;
        foreach ($courses as &$course) {
            self::keys($course, ["course_id", "choices"]);
            self::listing($course["choices"], 5000);
            self::id($course["course_id"]);
            if (isset($courseIds[$course["course_id"]])) {
                self::invalid();
            }
            $courseIds[$course["course_id"]] = true;
            $pairs = [];
            foreach ($course["choices"] as &$choice) {
                $keys = ["group_id", "target_course_id", "credits_milli", "work_ids"];
                if (array_key_exists('rule_credits_milli', $choice)) {
                    $keys[] = 'rule_credits_milli';
                    $choice['rule_credits_milli'] = self::validateContributions(
                        $choice['rule_credits_milli'],
                        $memberships[$choice['group_id']][$choice['target_course_id']] ?? [],
                        $choice['credits_milli'],
                        $contributionCount,
                    );
                }
                self::keys($choice, $keys);
                self::listing($choice["work_ids"], 200);
                self::id($choice["group_id"]);
                self::id($choice["target_course_id"]);
                self::quantity($choice["credits_milli"], 2147483646);
                $pair = $choice["group_id"] . ":" . $choice["target_course_id"] . ':' .
                json_encode($choice['rule_credits_milli'] ?? null, JSON_THROW_ON_ERROR);
                if (
                    ++$choiceCount > 5000 ||
                    isset($pairs[$pair]) ||
                    !isset($targets[$choice["group_id"]][$choice["target_course_id"]]) ||
                    count($choice["work_ids"]) < 1 ||
                    count(array_unique($choice["work_ids"])) !== count($choice["work_ids"])
                ) {
                    self::invalid();
                }
                $pairs[$pair] = true;
                foreach ($choice["work_ids"] as $work) {
                    self::id($work);
                    if (
                        ++$evidenceCount > 20000 ||
                        (isset($owners[$work]) && $owners[$work] !== $course["course_id"])
                    ) {
                        self::invalid();
                    }
                    $owners[$work] = $course["course_id"];
                }
                sort($choice["work_ids"], SORT_STRING);
                $signature = implode(":", $choice["work_ids"]);
                if (
                    isset($amounts[$course["course_id"]][$signature]) &&
                    $amounts[$course["course_id"]][$signature] !== $choice["credits_milli"]
                ) {
                    self::invalid();
                }
                $amounts[$course["course_id"]][$signature] = $choice["credits_milli"];
            }
            unset($choice);
            usort(
                $course["choices"],
                static fn(array $a, array $b): int => strcmp($a["group_id"], $b["group_id"]) ?:
                strcmp($a["target_course_id"], $b["target_course_id"]) ?:
                strcmp(
                    json_encode($a['rule_credits_milli'] ?? null, JSON_THROW_ON_ERROR),
                    json_encode($b['rule_credits_milli'] ?? null, JSON_THROW_ON_ERROR),
                ),
            );
        }
        unset($course);
        $courses = array_values(array_filter($courses, static fn(array $c): bool => $c["choices"] !== []));
        usort($groups, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        usort(
            $courses,
            static fn(array $a, array $b): int => count($a["choices"]) <=> count($b["choices"]) ?:
            strcmp($a["course_id"], $b["course_id"]),
        );
        $this->groups = $groups;
        $this->courses = $courses;
        $this->memberships = $memberships;
    }

    /**
     * Exact per-card contributions; omitted overrides use the named target's full award.
     * @param AllocationChoice $choice
     * @return array<string,int>
     */
    public function ruleCredits(#[\SensitiveParameter] array $choice): array
    {
        return $choice['rule_credits_milli'] ?? array_fill_keys(
            array_keys($this->memberships[$choice['group_id']][$choice['target_course_id']]),
            $choice['credits_milli'],
        );
    }

    /**
     * @param array<string,true> $allowed
     * @return array<string,int>
     */
    private static function validateContributions(mixed $value, array $allowed, int $maximum, int &$count): array
    {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            self::invalid();
        }
        foreach ($value as $id => $amount) {
            if (++$count > 20000 || !isset($allowed[$id]) || !is_int($amount)) {
                self::invalid();
            }
            self::quantity($amount, $maximum);
        }
        ksort($value, SORT_STRING);
        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function keys(array $row, array $keys): void
    {
        if (count($row) !== count($keys) || array_diff(array_keys($row), $keys) !== []) {
            self::invalid();
        }
    }
    /** @param array<array-key,mixed> $items */
    private static function listing(array $items, int $maximum): void
    {
        if (!array_is_list($items) || count($items) > $maximum) {
            self::invalid();
        }
    }
    private static function id(string $id): void
    {
        new EntityId($id);
    }
    private static function quantity(int $quantity, int $maximum): void
    {
        if ($quantity < 0 || $quantity > $maximum) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Use bounded, distinct course identities and valid retained allocation constraints.",
        );
    }
}
