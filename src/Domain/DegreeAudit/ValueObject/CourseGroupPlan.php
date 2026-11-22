<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;
use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

/** Catalog planning figures, independent of student awards and completion decisions. */
final readonly class CourseGroupPlan
{
    /** @param list<string> $warnings */
    private function __construct(
        public int $courseCount,
        public int $catalogCreditsMilli,
        public int $ruleCount,
        public array $warnings,
    ) {
    }

    /**
     * @param array<string, array<string,mixed>> $courses Resolved retained publications, keyed by course identity.
     * @throws Exception
     */
    public static function forDefinition(
        CourseGroupDefinition|CourseGroupDefinitionV2 $group,
        array $courses,
    ): self {
        $definition = $group->definition;
        $credits = 0;
        foreach ($definition["courses"] as $reference) {
            $courseCredits = $courses[$reference["course_id"]]["credits_milli"] ?? null;
            if (!is_int($courseCredits) || $courseCredits < 0 || $courseCredits > 999999) {
                throw new InvalidArgumentException(
                    trans_html("Resolve every listed course publication before planning this group."),
                );
            }
            $credits += $courseCredits;
        }
        $count = count($definition["courses"]);
        $warnings = [];
        if (
            (isset($definition["work_eligibility"]) &&
                array_replace(GroupWorkEligibility::DEFAULT, $definition["work_eligibility"])
                    != GroupWorkEligibility::DEFAULT) ||
            array_any($definition['rules'], static fn(array $rule): bool =>
                RequirementCreditOrigin::fromRule($rule) !== RequirementCreditOrigin::Any)
        ) {
            $warnings[] =
            trans_html(
                "Catalog totals do not check a student's credit origin or actual completion date. Run a student test."
            );
        }
        $hasCardGradeFloor = array_any(
            $definition['rules'],
            static fn(array $rule): bool => (RequirementGradeFloor::fromRule($rule)->pointsMilli ?? 0) > 0,
        );
        if ($hasCardGradeFloor) {
            $warnings[] = trans_html('Catalog totals do not check per-card grade minima. Run a student test.');
        }
        $dynamic =
        count(
            array_filter(
                $definition["rules"],
                static fn(array $r): bool => isset($r["eligibility"]),
            ),
        ) > 0;
        if ($dynamic) {
            $warnings[] = trans_html(
                'Listed totals exclude saved-rule matches. Run a student test to evaluate matching work.'
            );
        } elseif ($count === 0) {
            $warnings[] = trans_html("Add published courses to plan this group.");
        } elseif (($definition["requirement_type"] ?? "credits") === "courses") {
            if ($definition["min_courses"] > $count) {
                $warnings[] = trans_html("The required course count exceeds the number of distinct listed courses.");
            }
        } elseif ($definition["min_credits_milli"] > $credits) {
            $warnings[] =
            trans_html("The required credits exceed the catalog credits of the listed courses. Review this total.");
        }
        return new self($count, $credits, count($definition["rules"]), $warnings);
    }

    /** @return array{course_count:int, catalog_credits_milli:int, rule_count:int, warnings:list<string>} */
    public function describe(): array
    {
        return [
            "course_count" => $this->courseCount,
            "catalog_credits_milli" => $this->catalogCreditsMilli,
            "rule_count" => $this->ruleCount,
            "warnings" => $this->warnings,
        ];
    }
}
