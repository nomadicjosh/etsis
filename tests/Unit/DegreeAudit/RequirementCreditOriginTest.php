<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementCreditOriginTest extends TestCase
{
    private function definition(string $mode = "credits"): array
    {
        $d = CourseGroupDefinitionV2::blank("Fictional mixed coursework")->definition;
        $course = str_repeat("0", 25) . "1";
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [["course_id" => $course, "version_id" => str_repeat("0", 25) . "2"]];
        $d["rules"] = [
            [
                "id" => str_repeat("0", 25) . "3",
                "mode" => $mode,
                "minimum_courses" => in_array($mode, ["all", "choose"], true) ? 1 : 0,
                "course_ids" => [$course],
                "credit_origin" => "institutional",
            ],
        ];
        if ($mode === "credits") {
            $d["rules"][0]["minimum_credits_milli"] = 3000;
        }
        return $d;
    }

    public static function modes(): array
    {
        return [["all"], ["choose"], ["credits"], ["pool"]];
    }

    #[DataProvider("modes")]
    public function testEachCurrentCardRetainsItsOriginWhileEarlierAbsentFieldsRemainAbsent(
        string $mode,
    ): void {
        $d = $this->definition($mode);
        $value = new CourseGroupDefinitionV2($d);
        $value->assertPublishable();
        self::assertSame($d, $value->definition);
        $plan = CourseGroupPlan::forDefinition($value, [
            $d["courses"][0]["course_id"] => ["credits_milli" => 3000],
        ]);
        self::assertNotEmpty($plan->warnings);
        unset($d["rules"][0]["credit_origin"]);
        self::assertSame($d, new CourseGroupDefinitionV2($d)->definition);
    }

    public static function invalidOrigins(): array
    {
        return [[null], [false], [1], [[]], ["resident"], ["Institutional"]];
    }

    #[DataProvider("invalidOrigins")]
    public function testMalformedOriginsCannotBePublished(mixed $origin): void
    {
        $d = $this->definition();
        $d["rules"][0]["credit_origin"] = $origin;
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinitionV2($d);
    }

    public function testContradictoryMandatoryRulesStayEditableButCannotBePublished(): void
    {
        $d = $this->definition();
        $d["work_eligibility"]["credit_origin"] = "transfer";
        $draft = new CourseGroupDefinitionV2($d);
        self::assertSame($d, $draft->definition);
        $this->expectException(InvalidArgumentException::class);
        $draft->assertPublishable();
    }
}
