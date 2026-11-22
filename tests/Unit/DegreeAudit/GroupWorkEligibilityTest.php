<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupWorkEligibilityTest extends TestCase
{
    public static function sendingSources(): array
    {
        return [
            ["transfer", null, "transfer_institution_unknown"],
            ["transfer", str_repeat("0", 25) . "1", null],
            ["transfer", str_repeat("0", 25) . "2", "transfer_institution_excluded"],
            ["institutional", null, null],
            [null, null, "origin_unknown"],
        ];
    }

    #[DataProvider("sendingSources")]
    public function testSourceRestrictions(
        string|null $origin,
        string|null $source,
        string|null $expected,
    ): void {
        $policy = array_replace(GroupWorkEligibility::DEFAULT, [
            "transfer_institutions" => [
                ["id" => str_repeat("0", 25) . "1", "code" => "EXT", "name" => "Fictional college"],
            ],
        ]);
        self::assertSame(
            $expected,
            new GroupWorkEligibility($policy)->transferStatus($origin, $source)?->value,
        );
        self::assertNull(new GroupWorkEligibility()->transferStatus($origin, $source));
    }

    public static function invalidSources(): array
    {
        $item = ["id" => str_repeat("0", 25) . "1", "code" => "EXT", "name" => "Fictional college"];
        return [
            [[$item, $item], "any"],
            [array_fill(0, 21, $item), "any"],
            [[array_replace($item, ["id" => "invalid"])], "any"],
            [[array_replace($item, ["name" => ""])], "any"],
            [[array_replace($item, ["code" => " EXT"])], "any"],
            [[$item + ["private" => "hidden"]], "any"],
            [[$item], "institutional"],
            [["key" => $item], "any"],
            ["EXT", "any"],
        ];
    }

    #[DataProvider("invalidSources")]
    public function testInvalidSourcePoliciesAreRejected(mixed $sources, string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "credit_origin" => $origin,
                "transfer_institutions" => $sources,
            ]),
        );
    }

    public static function windows(): array
    {
        return [
            [null, null, null, null],
            ["2020-01-01", null, null, "completion_date_unknown"],
            [null, "2024-02-29", null, "completion_date_unknown"],
            ["2020-01-01", "2024-02-29", "2020-01-01", null],
            ["2020-01-01", "2024-02-29", "2024-02-29", null],
            ["2020-01-01", "2024-02-29", "2019-12-31", "completed_before_window"],
            ["2020-01-01", "2024-02-29", "2024-03-01", "completed_after_window"],
            [null, "2024-02-29", "1990-01-01", null],
            ["2020-01-01", null, "2090-01-01", null],
            ["2024-02-29", "2024-02-29", "2024-02-29", null],
        ];
    }

    #[DataProvider("windows")]
    public function testInclusiveActualCompletionWindows(
        ?string $from,
        ?string $through,
        ?string $completion,
        ?string $expected,
    ): void {
        $value = new GroupWorkEligibility([
            "credit_origin" => "any",
            "completed_from" => $from,
            "completed_through" => $through,
        ]);
        self::assertSame($expected, $value->completionStatus($completion)?->value);
    }

    public static function invalidFacts(): array
    {
        $default = GroupWorkEligibility::DEFAULT;
        return [
            [[]],
            [$default + ["extra" => true]],
            [array_replace($default, ["credit_origin" => "resident"])],
            [array_replace($default, ["completed_from" => "2023-02-29"])],
            [array_replace($default, ["completed_through" => "2024-02-30"])],
            [array_replace($default, ["completed_from" => "2026-1-01"])],
            [array_replace($default, ["completed_from" => "0000-01-01"])],
            [array_replace($default, ["completed_from" => true])],
            [
                array_replace($default, [
                    "completed_from" => "2026-08-11",
                    "completed_through" => "2026-08-10",
                ]),
            ],
        ];
    }

    #[DataProvider("invalidFacts")]
    public function testInvalidOrAmbiguousRestrictionsAreRejected(array $facts): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupWorkEligibility($facts);
    }

    public function testAbsentEarlierRestrictionsStayAbsentAndNewDraftsHaveExplicitUnrestrictedSettings(): void
    {
        $current = CourseGroupDefinitionV2::blank("Fictional group")->definition;
        self::assertSame(GroupWorkEligibility::DEFAULT, $current["work_eligibility"]);
        unset($current["work_eligibility"]);
        self::assertSame($current, CourseGroupDefinitionV2::draft($current)->definition);
        self::assertNull(new GroupWorkEligibility()->originStatus());
        $restricted = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, ["credit_origin" => "institutional"]),
        );
        self::assertSame("origin_unknown", $restricted->originStatus()?->value);
        self::assertSame("origin_excluded", $restricted->originStatus("transfer")?->value);
        self::assertNull($restricted->originStatus("institutional"));
    }
}
