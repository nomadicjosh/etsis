<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPlan;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CourseGroupPlanningTest extends TestCase
{
    private function definition(ReusableGroupFixture $f): array
    {
        $definition = $f->groupDefinition;
        unset($definition["min_gpa_milli"]);
        return $definition + [
            "requirement_type" => "credits",
            "min_courses" => 0,
            "min_course_grade_points_milli" => null,
            "max_course_grade_points_milli" => 4000,
            "grade_scale_program_id" => null,
        ];
    }

    public function testRepeatedCoursesAcrossCardsCountOnceInDraftReviewAndPublishedSummary(): void
    {
        $f = new ReusableGroupFixture();
        $definition = $this->definition($f);
        $definition["rules"][] = [
            "id" => EntityId::generate()->value,
            "mode" => "all",
            "minimum_courses" => 1,
            "course_ids" => [$definition["courses"][0]["course_id"]],
        ];
        $saved = $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($definition));
        $expected = [
            "course_count" => 5,
            "catalog_credits_milli" => 15000,
            "rule_count" => 5,
            "warnings" => [],
        ];
        self::assertSame($expected, $saved["planning_summary"]);
        $before = $f->digest("sis_history_events");
        $review = $f->groups->review($f->group, "Fictional group planning review");
        self::assertSame($expected, $review["planning_summary"]);
        self::assertSame($before, $f->digest("sis_history_events"));
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        self::assertSame(
            $expected,
            $f->groups->version($f->group, new EntityId($receipt["operation_id"]))["planning_summary"],
        );
    }

    public function testCatalogCreditWarningIsAnEstimateAndDoesNotChangePublicationPolicy(): void
    {
        $f = new ReusableGroupFixture();
        $definition = $this->definition($f);
        $definition["min_credits_milli"] = 16000;
        $draft = $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($definition));
        self::assertCount(1, $draft["planning_summary"]["warnings"]);
        self::assertStringContainsString(
            "required credits exceed",
            $draft["planning_summary"]["warnings"][0],
        );
        $review = $f->groups->review($f->group, "Fictional catalog estimate acknowledged");
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        self::assertSame($receipt, $f->groups->publish($f->group, $review["review_token"]));
        self::assertSame(
            $draft["planning_summary"],
            $f->groups->version($f->group, new EntityId($receipt["operation_id"]))["planning_summary"],
        );
    }

    public function testCourseCountDraftWarnsBeforeTheExistingPublicationValidation(): void
    {
        $f = new ReusableGroupFixture();
        $definition = $this->definition($f);
        $definition["requirement_type"] = "courses";
        $definition["min_credits_milli"] = 0;
        $definition["min_courses"] = 6;
        $draft = $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($definition));
        self::assertSame(
            ["The required course count exceeds the number of distinct listed courses."],
            $draft["planning_summary"]["warnings"],
        );
        $before = $f->digest("sis_history_events");
        try {
            $f->groups->review($f->group, "Fictional incomplete course-count review");
            self::fail("The existing course-count publication limit was bypassed.");
        } catch (InvalidArgumentException) {
            self::assertSame($before, $f->digest("sis_history_events"));
        }
        $definition["min_courses"] = 5;
        self::assertSame(
            [],
            $f->groups->save($f->group, 1, new CourseGroupDefinitionV2($definition))["planning_summary"][
                "warnings"
            ],
        );
    }

    public function testLaterCourseChangesAffectOnlyExplicitlyAdoptedGroupVersions(): void
    {
        $f = new ReusableGroupFixture();
        $original = $f->groups->version($f->group, $f->groupVersion);
        $course = new EntityId($f->groupDefinition["courses"][0]["course_id"]);
        $f->sis->sql->update("sis_courses", ["credits_milli" => 9000], ["id" => $course->value]);
        self::assertSame(15000, $f->groups->view($f->group)["planning_summary"]["catalog_credits_milli"]);
        $review = $f->catalog->publications()->review($course, "Fictional increased catalog credits");
        $publication = $f->catalog->publications()->publish($course, $review["review_token"]);
        self::assertSame(15000, $f->groups->view($f->group)["planning_summary"]["catalog_credits_milli"]);
        $definition = $f->groupDefinition;
        $definition["courses"][0]["version_id"] = $publication["operation_id"];
        $draft = $f->groups->save($f->group, 0, new CourseGroupDefinition($definition));
        self::assertSame(21000, $draft["planning_summary"]["catalog_credits_milli"]);
        $newGroup = $f->publishGroup();
        self::assertSame(
            21000,
            $f->groups->version($f->group, $newGroup)["planning_summary"]["catalog_credits_milli"],
        );
        self::assertSame($original, $f->groups->version($f->group, $f->groupVersion));
    }

    public function testCatalogTotalsUseExactThousandthsIncludeZeroCreditCoursesAndIgnoreUnlistedDetails(): void
    {
        $f = new ReusableGroupFixture();
        $definition = $this->definition($f);
        $details = [];
        $amounts = [3125, 0, 3775, 3000, 3000];
        foreach ($definition["courses"] as $index => $reference) {
            $details[$reference["course_id"]] = ["credits_milli" => $amounts[$index]];
        }
        $details[EntityId::generate()->value] = ["credits_milli" => 999999];
        $definition["min_credits_milli"] = 12900;
        $summary = CourseGroupPlan::forDefinition(new CourseGroupDefinitionV2($definition), $details);
        self::assertSame(12900, $summary->catalogCreditsMilli);
        self::assertSame(5, $summary->courseCount);
        self::assertSame([], $summary->warnings);
        $definition["min_credits_milli"]++;
        self::assertCount(
            1,
            CourseGroupPlan::forDefinition(new CourseGroupDefinitionV2($definition), $details)->warnings,
        );
    }

    public function testMissingCourseDetailsNeverBecomeZeroCreditEstimates(): void
    {
        $f = new ReusableGroupFixture();
        $definition = new CourseGroupDefinition($f->groupDefinition);
        $this->expectException(InvalidArgumentException::class);
        CourseGroupPlan::forDefinition($definition, []);
    }

    public function testEmptyWorkingGroupHasSetupGuidanceWithoutInventedCourses(): void
    {
        $plan = CourseGroupPlan::forDefinition(
            CourseGroupDefinitionV2::blank("Fictional draft"),
            [],
        )->describe();
        self::assertSame(0, $plan["course_count"]);
        self::assertSame(0, $plan["catalog_credits_milli"]);
        self::assertSame(0, $plan["rule_count"]);
        self::assertSame(["Add published courses to plan this group."], $plan["warnings"]);
    }
}
