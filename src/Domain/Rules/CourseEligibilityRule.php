<?php

declare(strict_types=1);

namespace Domain\Rules;

use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Pure course eligibility. Neither student state, catalog discovery nor earned-credit calculation. */
final readonly class CourseEligibilityRule
{
    public const array FIELDS = [
        "course_id" => "string",
        "subject_id" => "string",
        "department_id" => "string",
        "academic_level_id" => "string",
        "course_level_id" => "string",
        "course_level_number" => "integer",
    ];
    public RuleSet $rules;

    /** @param array<string,mixed> $tree */
    public function __construct(array $tree)
    {
        if (strlen(json_encode($tree, JSON_THROW_ON_ERROR)) > 32768) {
            throw new InvalidArgumentException("Use a course rule no larger than 32 KiB.");
        }
        $this->rules = new RuleSet($tree, self::FIELDS);
        $this->references();
    }
    /** @return array<string,list<string>> Identity conditions only; labels never decide a match. */
    public function references(): array
    {
        $references = [];
        $count = 0;
        $walk = function (array $group) use (&$walk, &$references, &$count): void {
            foreach ($group["rules"] as $rule) {
                if (isset($rule["condition"])) {
                    $walk($rule);
                    continue;
                }
                foreach (is_array($rule["value"]) ? $rule["value"] : [$rule["value"]] as $value) {
                    if ($rule["id"] === "course_level_number") {
                        if (!is_int($value) || $value < 0 || $value > 999999) {
                            throw new InvalidArgumentException(
                                "Use a numeric course level between 0 and 999999.",
                            );
                        }
                    } else {
                        if (!is_string($value)) {
                            throw new InvalidArgumentException(
                                "Select a catalog identity for this condition.",
                            );
                        }
                        new EntityId($value);
                        if (!isset($references[$rule["id"]][$value]) && ++$count > 200) {
                            throw new InvalidArgumentException(
                                "Use at most 200 distinct catalog references.",
                            );
                        }
                        $references[$rule["id"]][$value] = true;
                    }
                }
            }
        };
        $walk($this->rules->tree);
        $result = [];
        foreach ($references as $field => $ids) {
            $result[$field] = array_keys($ids);
            sort($result[$field], SORT_STRING);
        }
        ksort($result, SORT_STRING);
        return $result;
    }
    public function decision(EntityId $course, CourseDefinition $definition): ?bool
    {
        $level = $definition->classifications->courseLevel;
        return $this->rules->decision([
            "course_id" => $course->value,
            "subject_id" => $definition->subject?->id->value,
            "department_id" => $definition->classifications->department?->id->value,
            "academic_level_id" => $definition->classifications->academicLevel?->id->value,
            "course_level_id" => $level?->id->value,
            // Only the separately defined, retained level code. Never parse the course code or title.
            "course_level_number" =>
                $level !== null && preg_match('/^[0-9]{1,6}$/D', $level->code) === 1
                    ? (int) $level->code
                    : null,
        ]);
    }
}
