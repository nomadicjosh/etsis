<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class CourseGroupQuantityTest extends TestCase
{
    private function definition(ReusableGroupFixture $f): array
    {
        $d = $f->groupDefinition;
        unset($d["min_gpa_milli"]);
        return $d + [
            "requirement_type" => "credits",
            "min_courses" => 0,
            "min_course_grade_points_milli" => 2000,
            "max_course_grade_points_milli" => 4000,
            "grade_scale_program_id" => null,
        ];
    }
    public function testCreditListThresholdPublishesAndReplaysWithoutChangingEarlierDefinitions(): void
    {
        $f = new ReusableGroupFixture();
        $old = $f->groups->version($f->group, $f->groupVersion);
        $events = $f->sis->sql->rows("sis_history_events");
        $d = $this->definition($f);
        $d["min_course_grade_points_milli"] = null;
        $d["rules"] = [[
            "id" => EntityId::generate()->value,
            "mode" => "credits", "minimum_courses" => 0, "minimum_credits_milli" => 6000,
            "course_ids" => array_column($d["courses"], "course_id"),
        ]];
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $version = $f->publishGroup();
        $published = $f->groups->version($f->group, $version);
        self::assertSame(2, $published["schema_version"]);
        self::assertSame(6000, $published["definition"]["rules"][0]["minimum_credits_milli"]);
        $facts = new \Domain\DegreeAudit\ValueObject\CourseGroupAuthoringFacts(
            new CourseGroupDefinitionV2($d), $published["course_details"],
        )->describe();
        self::assertSame(6000, $facts["rules"][0]["minimum_credits_milli"]);
        self::assertArrayNotHasKey("course_ids", $facts["rules"][0]);
        $d["rules"][0]["minimum_credits_milli"] = 3000;
        $f->groups->save($f->group, 1, new CourseGroupDefinitionV2($d));
        $f->publishGroup();
        self::assertSame($published, $f->groups->version($f->group, $version));
        self::assertSame($old, $f->groups->version($f->group, $f->groupVersion));
        foreach ($events as $event) {
            self::assertSame($event, $f->sis->sql->one("sis_history_events", ["id" => $event["id"]]));
        }
    }
    public function testCreditsAndCourseCountPublishAsDifferentGroupChoicesWithoutChangingOldVersions(): void
    {
        $f = new ReusableGroupFixture();
        $old = $f->groups->version($f->group, $f->groupVersion);
        $d = $this->definition($f);
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $v = $f->publishGroup();
        $credits = $f->groups->version($f->group, $v);
        self::assertSame(2, $credits["schema_version"]);
        self::assertSame("credits", $credits["definition"]["requirement_type"]);
        self::assertSame(2000, $credits["definition"]["min_course_grade_points_milli"]);
        $d["requirement_type"] = "courses";
        $d["min_credits_milli"] = 0;
        $d["min_courses"] = 4;
        $f->groups->save($f->group, 1, new CourseGroupDefinitionV2($d));
        $count = $f->publishGroup();
        self::assertSame(4, $f->groups->version($f->group, $count)["definition"]["min_courses"]);
        self::assertSame($old, $f->groups->version($f->group, $f->groupVersion));
        self::assertSame($credits, $f->groups->version($f->group, $v));
    }
    public function testPerCoursePointFloorUsesGradeDefinitionUnitsRatherThanAverageOrWeightedQualityPoints(): void
    {
        $f = new ReusableGroupFixture();
        $d = $this->definition($f);
        $g = new CourseGroupDefinitionV2($d);
        self::assertFalse(
            $g->permitsCompletedGrade(new GradeDefinition(EntityId::generate(), "C-", 1999, true, true)),
        );
        self::assertTrue(
            $g->permitsCompletedGrade(new GradeDefinition(EntityId::generate(), "C", 2000, true, true)),
        );
        self::assertTrue(
            $g->permitsCompletedGrade(new GradeDefinition(EntityId::generate(), "A", 4000, true, true)),
        );
        self::assertFalse(
            $g->permitsCompletedGrade(new GradeDefinition(EntityId::generate(), "F", 2000, false, true)),
        );
        $d["min_course_grade_points_milli"] = null;
        self::assertTrue(
            new CourseGroupDefinitionV2($d)->permitsCompletedGrade(
                new GradeDefinition(EntityId::generate(), "P", 0, true, false),
            ),
        );
        self::assertArrayNotHasKey("min_gpa_milli", $g->definition);
    }
    public function testInvalidMixedQuantityFractionalCountsAndAmbiguousGpaAreRejected(): void
    {
        $f = new ReusableGroupFixture();
        $base = $this->definition($f);
        $cases = [];
        $d = $base;
        $d["min_courses"] = 2;
        $cases[] = $d;
        $d = $base;
        $d["requirement_type"] = "courses";
        $d["min_courses"] = 2;
        $cases[] = $d;
        $d["min_credits_milli"] = 0;
        $d["min_courses"] = 2.5;
        $cases[] = $d;
        $d = $base;
        $d["min_course_grade_points_milli"] = "2.0";
        $cases[] = $d;
        $d = $base;
        $d["min_gpa_milli"] = 2000;
        $cases[] = $d;
        foreach ($cases as $d) {
            try {
                new CourseGroupDefinitionV2($d);
                self::fail("Ambiguous group contract accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $base["requirement_type"] = "courses";
        $base["min_credits_milli"] = 0;
        $base["min_courses"] = 6;
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinitionV2($base)->assertPublishable();
    }
    public function testSchemaOneDoesNotReinterpretNewThresholdsAndAcceptedOldReceiptsRemainValid(): void
    {
        $f = new ReusableGroupFixture();
        $review = $f->groups->review($f->group, "Fictional old grammar");
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        $prefix = $f->sis->sql->rows("sis_history_events");
        $d = $this->definition($f);
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $v = $f->publishGroup();
        self::assertSame($prefix, array_slice($f->sis->sql->rows("sis_history_events"), 0, count($prefix)));
        self::assertSame($receipt, $f->groups->publish($f->group, $review["review_token"]));
        try {
            new CourseGroupDefinition($d);
            self::fail("Version 2 was read as version 1.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $event = \Domain\DegreeAudit\Event\CourseGroupWasPublished::occur(
            new \Domain\DegreeAudit\ValueObject\CourseGroupPublicationId($f->group->value),
            ["version_id" => $v->value, "previous_version_id" => $f->groupVersion->value, "definition" => $d],
            ["__aggregate_type" => "course_group_publication"],
        )->withPlayhead(4);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()->describe($event);
    }
    public function testMaximumDefaultsOnlyBeforeSetupAndUsesTheLargestConfiguredGrade(): void
    {
        $f = new ReusableGroupFixture();
        self::assertSame(
            ["maximum_milli" => 4000, "is_default" => true],
            array_intersect_key(
                $f->groups->view($f->group)["grade_point_scale"],
                array_flip(["maximum_milli", "is_default"]),
            ),
        );
        $gradeRows = [
            [
                "code" => "P",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
        ];
        $f->sis->gradeScale($gradeRows);
        self::assertSame(
            ["maximum_milli" => 0, "is_default" => false],
            array_intersect_key(
                $f->groups->view($f->group)["grade_point_scale"],
                array_flip(["maximum_milli", "is_default"]),
            ),
        );
        $gradeRows[] = [
            "code" => "A+",
            "min_score_milli" => null,
            "max_score_milli" => null,
            "points_milli" => 4300,
            "passes" => true,
            "counts_gpa" => true,
        ];
        $gradeRows[] = [
            "code" => "C",
            "min_score_milli" => null,
            "max_score_milli" => null,
            "points_milli" => 1700,
            "passes" => true,
            "counts_gpa" => true,
        ];
        $f->sis->gradeScale($gradeRows);
        self::assertSame(
            ["maximum_milli" => 4300, "is_default" => false],
            array_intersect_key(
                $f->groups->page()["grade_point_scale"],
                array_flip(["maximum_milli", "is_default"]),
            ),
        );
        $d = $this->definition($f);
        $d["max_course_grade_points_milli"] = 4300;
        $d["min_course_grade_points_milli"] = 1700;
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $v = $f->publishGroup();
        self::assertSame(
            4300,
            $f->groups->version($f->group, $v)["definition"]["max_course_grade_points_milli"],
        );
        self::assertTrue(
            new CourseGroupDefinitionV2($d)->permitsCompletedGrade(
                new GradeDefinition(EntityId::generate(), "C", 1700, true, true),
            ),
        );
        $d["min_course_grade_points_milli"] = 4301;
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinitionV2($d);
    }
    public function testChangedScaleInvalidatesReviewWithoutChangingEarlierPublicationsOrAcceptedRetries(): void
    {
        $f = new ReusableGroupFixture();
        $d = $this->definition($f);
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $oldReview = $f->groups->review($f->group, "Fictional four-point scale");
        $receipt = $f->groups->publish($f->group, $oldReview["review_token"]);
        $published = $f->groups->version($f->group, new EntityId($receipt["operation_id"]));
        $stale = $f->groups->review($f->group, "Fictional pending review");
        $f->sis->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 5000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $events = $f->sis->sql->rows("sis_history_events");
        try {
            $f->groups->publish($f->group, $stale["review_token"]);
            self::fail("Changed grading scale was silently accepted.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertTrue(true);
        }
        self::assertSame($events, $f->sis->sql->rows("sis_history_events"));
        self::assertSame($receipt, $f->groups->publish($f->group, $oldReview["review_token"]));
        self::assertSame($published, $f->groups->version($f->group, new EntityId($receipt["operation_id"])));
        self::assertSame(5000, $f->groups->view($f->group)["grade_point_scale"]["maximum_milli"]);
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $f->groups->review($f->group, "Fictional stale draft scale");
    }
    public function testClientCannotChooseAnUnconfiguredMaximum(): void
    {
        $f = new ReusableGroupFixture();
        $d = $this->definition($f);
        $d["max_course_grade_points_milli"] = 5000;
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
    }
}
