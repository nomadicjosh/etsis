<?php

declare(strict_types=1);

use Domain\DegreeAudit\ValueObject\CourseGroupAuthoringFacts;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CourseGroupAuthoringFactsTest extends TestCase
{
    public function testEarlierAverageGpaIsNotReinterpretedAsPerCourseGradePoints(): void
    {
        $legacy = CourseGroupDefinition::blank("Fictional earlier group")->definition;
        $legacy["min_gpa_milli"] = 2750;
        $facts = new CourseGroupAuthoringFacts(new CourseGroupDefinition($legacy), [])->describe();
        self::assertSame(2750, $facts["legacy_average_group_gpa_milli"]);
        self::assertArrayNotHasKey("min_course_grade_points_milli", $facts);
        self::assertArrayNotHasKey("max_course_grade_points_milli", $facts);
        $current = CourseGroupDefinitionV2::blank("Fictional current group", 7000)->definition;
        $current["min_course_grade_points_milli"] = 3500;
        $value = new CourseGroupAuthoringFacts(new CourseGroupDefinitionV2($current), []);
        self::assertSame(7000, $value->describe()["max_course_grade_points_milli"]);
        self::assertSame(3500, $value->describe()["min_course_grade_points_milli"]);
        self::assertArrayNotHasKey("legacy_average_group_gpa_milli", $value->describe());
        self::assertStringNotContainsString("Fictional current group", print_r($value, true));
    }
    public function testMatchingCourseCodesCannotFabricateSharedCourseIdentity(): void
    {
        $first = EntityId::generate()->value;
        $second = EntityId::generate()->value;
        $d = CourseGroupDefinitionV2::blank("Fictional repeated code")->definition;
        $d["courses"] = array_map(
            static fn($id) => ["course_id" => $id, "version_id" => EntityId::generate()->value],
            [$first, $second],
        );
        $d["rules"] = [
            [
                "id" => EntityId::generate()->value,
                "mode" => "choose",
                "minimum_courses" => 1,
                "course_ids" => [$first, $second],
            ],
        ];
        $course = [
            "code" => "MATH.101",
            "title" => "Fictional Algebra",
            "credits_milli" => 3000,
            "description" => "Excluded source text",
        ];
        $facts = new CourseGroupAuthoringFacts(
            new CourseGroupDefinitionV2($d),
            [$first => $course, $second => $course],
            [$first => 7, $second => 8],
        )->describe();
        self::assertSame([7, 8], array_column($facts["courses"], "degree_course_position"));
        self::assertSame([1, 2], $facts["rules"][0]["course_positions"]);
        foreach ([$first, $second, "Excluded source text"] as $private) {
            self::assertStringNotContainsString($private, json_encode($facts));
        }
    }
    public function testUnresolvedCourseDoesNotCreatePartialAuthoringFacts(): void
    {
        $d = CourseGroupDefinitionV2::blank("Fictional missing source")->definition;
        $course = EntityId::generate()->value;
        $d["courses"] = [["course_id" => $course, "version_id" => EntityId::generate()->value]];
        $d["rules"] = [
            [
                "id" => EntityId::generate()->value,
                "mode" => "all",
                "minimum_courses" => 1,
                "course_ids" => [$course],
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupAuthoringFacts(new CourseGroupDefinitionV2($d), []);
    }
}
