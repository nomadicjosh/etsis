<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\DegreeMinimumEvaluator;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DegreeMinimumEvaluatorTest extends TestCase
{
    private static function id(int $i): EntityId
    {
        return new EntityId(str_pad((string) $i, 26, "0", STR_PAD_LEFT));
    }
    private function attempt(
        int $id,
        string $origin = "institutional",
        int $points = 4000,
        int $course = 1,
        int $credits = 3000,
        bool $gpa = true,
    ): PolicyAttempt {
        $grade = new GradeSnapshot([
            "id" => self::id(1000 + $id)->value,
            "code" => $points === 0 ? "F" : "P",
            "points_milli" => $points,
            "passes" => $points !== 0,
            "counts_gpa" => $gpa,
            "scale_id" => self::id(99)->value,
            "scale_version_id" => self::id(100)->value,
        ]);
        return new PolicyAttempt(
            self::id($id),
            self::id($course),
            $origin,
            "A",
            $credits,
            $points === 0 ? 0 : $credits,
            $gpa ? intdiv($credits * $points, 1000) : 0,
            true,
            $grade,
            academicOn: $origin === "transfer" ? "2027-01-01" : "2026-08-01",
        );
    }
    private function policy(
        RepeatGpaPolicy $gpa = RepeatGpaPolicy::AllEligible,
        ?TransferGpaPolicy $transfer = TransferGpaPolicy::IncludeEligible,
    ): AuditCalculationPolicy {
        return new AuditCalculationPolicy(
            RepeatCreditPolicy::HighestEarned,
            $gpa,
            $transfer,
            self::id(100),
            RepeatAttemptOrder::AcademicPeriod,
        );
    }
    private function results(array $attempts, ?AuditCalculationPolicy $policy = null): array
    {
        $policy ??= $this->policy();
        $engine = new AcademicPolicyEvaluator();
        return [
            $engine->evaluate($attempts, $policy),
            $engine->evaluate($attempts, $policy, AcademicGpaScope::Institutional),
        ];
    }
    private function minimums(
        int $credits = 6000,
        int $resident = 3000,
        ?int $cumulative = 3500,
        ?int $overall = 3500,
    ): array {
        return [
            "min_credits_milli" => $credits,
            "min_resident_milli" => $resident,
            "min_cumulative_gpa_milli" => $cumulative,
            "min_overall_gpa_milli" => $overall,
        ];
    }
    public function testCumulativeExcludesTransfersAndOverallRetainsTheInstitutionSelectedTransferPolicy(): void
    {
        [$overall, $institutional] = $this->results([
            $this->attempt(10),
            $this->attempt(11, "transfer", 2000, 2),
        ]);
        self::assertSame(3000, $overall->gpa->milli());
        self::assertSame(4000, $institutional->gpa->milli());
        self::assertSame(6000, $overall->earnedMilli);
        self::assertSame($overall->earnedMilli, $institutional->earnedMilli);
        self::assertSame($overall->policy, $institutional->policy);
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $institutional, $this->minimums());
        self::assertSame(
            ["satisfied", "satisfied", "satisfied", "below_minimum"],
            array_column($rows, "status"),
        );
        [$excluded, $cumulative] = $this->results(
            [$this->attempt(10), $this->attempt(11, "transfer", 2000, 2)],
            $this->policy(transfer: TransferGpaPolicy::Exclude),
        );
        self::assertSame(4000, $excluded->gpa->milli());
        self::assertSame($excluded->gpa->milli(), $cumulative->gpa->milli());
    }
    public function testTransfersAreExcludedBeforeHighestAndLatestRepeatSelectionForCumulativeGpa(): void
    {
        foreach ([RepeatGpaPolicy::HighestGrade, RepeatGpaPolicy::LatestEligible] as $policy) {
            [$overall, $cumulative] = $this->results(
                [$this->attempt(10, points: 3000), $this->attempt(11, "transfer", 4000)],
                $this->policy($policy),
            );
            self::assertSame(4000, $overall->gpa->milli());
            self::assertSame(3000, $cumulative->gpa->milli());
            self::assertSame(3000, $overall->earnedMilli);
            self::assertSame(0, $overall->residentMinimumMilli);
            self::assertSame(3000, $overall->residentMaximumMilli);
        }
    }
    public function testUncertainResidentOriginDoesNotSilentlyChooseAnInstitutionalRepeat(): void
    {
        [$overall, $cumulative] = $this->results([$this->attempt(10), $this->attempt(11, "transfer")]);
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums(3000, 1000));
        self::assertSame("unknown", $rows[1]["status"]);
        self::assertSame(0, $rows[1]["actual_minimum_milli"]);
        self::assertSame(3000, $rows[1]["actual_maximum_milli"]);
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums(4000, 3500));
        self::assertSame("below_minimum", $rows[1]["status"]);
    }
    public function testUnknownTransferGradeDoesNotEraseKnownInstitutionalGpaOrCreatePartialCreditTotals(): void
    {
        $unknown = new PolicyAttempt(self::id(11), self::id(2), "transfer", "A", 3000, 3000, 9000, true);
        [$overall, $cumulative] = $this->results([$this->attempt(10), $unknown]);
        self::assertNull($overall->earnedMilli);
        self::assertNull($cumulative->earnedMilli);
        self::assertNull($overall->gpa);
        self::assertSame(4000, $cumulative->gpa->milli());
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums());
        self::assertSame(["unknown", "unknown", "satisfied", "unknown"], array_column($rows, "status"));
    }
    public function testUnknownOverallTransferPolicyDoesNotInventOneForCumulativeScope(): void
    {
        [$overall, $cumulative] = $this->results([$this->attempt(10)], $this->policy(transfer: null));
        self::assertNull($overall->gpa);
        self::assertSame(4000, $cumulative->gpa->milli());
        self::assertNull($cumulative->policy->transferGpa);
        self::assertSame(AcademicGpaScope::Institutional, $cumulative->gpaScope);
    }
    public function testNoGpaDenominatorRemainsUnknownForAPositiveMinimumAndAnFOnlyGpaIsZero(): void
    {
        foreach ([[], [$this->attempt(10, gpa: false)]] as $work) {
            [$overall, $cumulative] = $this->results($work);
            self::assertSame(0, $cumulative->gpa->creditsMilli);
            $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums());
            self::assertSame("unknown", $rows[2]["status"]);
            self::assertNull($rows[2]["actual_minimum_milli"]);
        }
        [$overall, $cumulative] = $this->results([$this->attempt(10, points: 0)]);
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums());
        self::assertSame("below_minimum", $rows[2]["status"]);
        self::assertSame(0, $rows[2]["actual_minimum_milli"]);
    }
    public function testBlankOrZeroMinimumDoesNotAcquireANewCondition(): void
    {
        [$overall, $cumulative] = $this->results([]);
        $rows = new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums(0, 0, null, 0));
        self::assertSame(
            ["not_required", "not_required", "not_required", "not_required"],
            array_column($rows, "status"),
        );
        self::assertNull($rows[2]["actual_minimum_milli"]);
    }
    public function testGpaBelowTheBoundaryCannotRoundUpToMeetItsMinimum(): void
    {
        [$overall, $cumulative] = $this->results([
            $this->attempt(10, points: 3000, credits: 1999999),
            $this->attempt(11, points: 2000, course: 2, credits: 1),
        ]);
        $rows = new DegreeMinimumEvaluator()->evaluate(
            $overall,
            $cumulative,
            $this->minimums(1, 0, 3000, 3000),
        );
        self::assertSame("below_minimum", $rows[2]["status"]);
        self::assertSame(2999, $rows[2]["actual_minimum_milli"]);
        [$overall, $cumulative] = $this->results([$this->attempt(10, points: 3000)]);
        self::assertSame(
            "satisfied",
            new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums(1, 0, 3000))[2][
                "status"
            ],
        );
    }
    public function testTheSameOverallResultCannotBePassedAsCumulativeOrMixRetainedPolicies(): void
    {
        [$overall] = $this->results([$this->attempt(10)]);
        $this->expectException(InvalidArgumentException::class);
        new DegreeMinimumEvaluator()->evaluate($overall, $overall, $this->minimums());
    }
    public function testInvalidEffectiveMinimumsAreRejected(): void
    {
        [$overall, $cumulative] = $this->results([]);
        $this->expectException(InvalidArgumentException::class);
        new DegreeMinimumEvaluator()->evaluate($overall, $cumulative, $this->minimums(3000, 4000));
    }
}
