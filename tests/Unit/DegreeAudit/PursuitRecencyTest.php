<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PursuitRecencyTest extends TestCase
{
    public static function boundaries(): array
    {
        return [
            ["2026-08-31", 1, "2026-07-31"],
            ["2026-03-31", 1, "2026-02-28"],
            ["2024-03-31", 1, "2024-02-29"],
            ["2024-02-29", 12, "2023-02-28"],
            ["2026-08-10", 60, "2021-08-10"],
            ["2100-03-31", 1, "2100-02-28"],
            ["2026-08-10", 1200, "1926-08-10"],
            ["0001-02-01", 1200, "0001-01-01"],
        ];
    }
    #[DataProvider("boundaries")]
    public function testInclusiveCalendarBoundary(
        string $start,
        int $months,
        string $expected,
    ): void {
        $policy = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "max_age_months_at_pursuit_start" => $months,
            ]),
        );
        self::assertSame(
            ["pursuit_started_on" => $start, "earliest_completion_on" => $expected],
            $policy->recencyContext($start),
        );
        self::assertNull($policy->completionStatus($expected, $start));
        if ($expected !== "0001-01-01") {
            $older = new \DateTimeImmutable($expected, new \DateTimeZone("UTC"))
                ->modify("-1 day")
                ->format("Y-m-d");
            self::assertSame(
                "completion_too_old",
                $policy->completionStatus($older, $start)?->value,
            );
        }
    }
    public static function invalidMonths(): array
    {
        return [[0], [-1], [1201], [1.5], ["60"], [true], [[]]];
    }
    #[DataProvider("invalidMonths")]
    public function testOnlyBoundedWholeMonthsAreAccepted(mixed $months): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "max_age_months_at_pursuit_start" => $months,
            ]),
        );
    }
    public function testUnknownDatesRemainUnknownAndKnownFixedExclusionsRemainDecisive(): void
    {
        $policy = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "max_age_months_at_pursuit_start" => 60,
                "completed_from" => "2020-01-01",
                "completed_through" => "2030-01-01",
            ]),
        );
        self::assertSame(
            "completion_date_unknown",
            $policy->completionStatus(null, "2026-08-10")?->value,
        );
        self::assertSame("pursuit_start_unknown", $policy->completionStatus("2021-08-10")?->value);
        self::assertSame(
            "completed_before_window",
            $policy->completionStatus("2019-12-31")?->value,
        );
        self::assertSame("completed_after_window", $policy->completionStatus("2030-01-02")?->value);
        self::assertNull($policy->completionStatus("2027-01-01", "2026-08-10"));
    }
    public function testOlderPolicyFactsRetainTheirExactShapeAndUnrestrictedBehavior(): void
    {
        $older = ["credit_origin" => "any", "completed_from" => null, "completed_through" => null];
        $policy = new GroupWorkEligibility($older);
        self::assertSame($older, $policy->facts);
        self::assertNull($policy->completionStatus());
        self::assertFalse(
            GroupWorkEligibility::requiresPursuitStart([
                "group_details" => [["definition" => ["work_eligibility" => $older]]],
            ]),
        );
    }
}
