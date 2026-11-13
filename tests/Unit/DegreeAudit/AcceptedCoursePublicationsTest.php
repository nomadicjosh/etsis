<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AcceptedCoursePublicationsTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, "0", STR_PAD_LEFT);
    }

    private function definition(): array
    {
        $d = CourseGroupDefinitionV2::blank("Fictional accepted publications")->definition;
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [["course_id" => self::id(1), "version_id" => self::id(2)]];
        $d["rules"] = [
            ["id" => self::id(4), "mode" => "all", "minimum_courses" => 1, "course_ids" => [self::id(1)]],
        ];
        return $d;
    }

    public function testAdditionalVersionsDoNotCreateMoreCoursesOrCatalogCredits(): void
    {
        $d = $this->definition();
        $d["courses"][0]["accepted_version_ids"] = [self::id(3)];
        $definition = new CourseGroupDefinitionV2($d);
        $definition->assertPublishable();
        $plan = CourseGroupPlan::forDefinition($definition, [
            self::id(1) => [
                "credits_milli" => 3000,
                "accepted_versions" => [["credits_milli" => 6000]],
            ],
        ])->describe();
        self::assertSame(1, $plan["course_count"]);
        self::assertSame(3000, $plan["catalog_credits_milli"]);
    }

    public function testAcceptedReferencesMustBeDistinctExplicitBoundedPublications(): void
    {
        $invalid = [
            null,
            [self::id(2)],
            [self::id(3), self::id(3)],
            ["not-a-publication"],
            array_map(self::id(...), range(10, 20)),
            ["named" => self::id(3)],
        ];
        foreach ($invalid as $references) {
            $d = $this->definition();
            $d["courses"][0]["accepted_version_ids"] = $references;
            try {
                new CourseGroupDefinitionV2($d);
                self::fail("Invalid accepted versions were allowed.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
