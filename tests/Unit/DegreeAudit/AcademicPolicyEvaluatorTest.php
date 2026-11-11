<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\ValueObject\AcademicPolicyResult;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\GpaTotals;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy as Credit;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy as Gpa;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy as Transfer;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcademicPolicyEvaluatorTest extends TestCase
{
    private static function id(int $n): EntityId
    {
        return new EntityId(str_pad((string) $n, 26, "0", STR_PAD_LEFT));
    }
    private function attempt(
        int $id,
        int $course = 1,
        int $credits = 3000,
        int $points = 4000,
        bool $passes = true,
        bool $gpa = true,
        string $origin = "institutional",
        string $status = "A",
        ?string $date = "2026-05-01",
        ?int $scale = 100,
        ?int $earned = null,
    ): PolicyAttempt {
        $grade = new GradeSnapshot([
            "id" => self::id(1000 + $id)->value,
            "code" => $passes ? "P" : "F",
            "points_milli" => $points,
            "passes" => $passes,
            "counts_gpa" => $gpa,
            "scale_id" => $scale === null ? null : self::id(101)->value,
            "scale_version_id" => $scale === null ? null : self::id($scale)->value,
        ]);
        return new PolicyAttempt(
            self::id($id),
            self::id($course),
            $origin,
            $status,
            $credits,
            $earned ?? ($passes && $status !== "W" ? $credits : 0),
            $gpa ? intdiv($credits * $points, 1000) : 0,
            true,
            $grade,
            $date,
            $date,
        );
    }
    private function policy(
        ?Credit $credit = Credit::HighestEarned,
        ?Gpa $gpa = Gpa::AllEligible,
        ?Transfer $transfer = Transfer::Exclude,
        ?int $scale = 100,
    ): AuditCalculationPolicy {
        return new AuditCalculationPolicy(
            $credit,
            $gpa,
            $transfer,
            $scale === null ? null : self::id($scale),
            RepeatAttemptOrder::AcademicPeriod,
        );
    }
    private function evaluate(array $attempts, ?AuditCalculationPolicy $policy = null): AcademicPolicyResult
    {
        return new AcademicPolicyEvaluator()->evaluate($attempts, $policy ?? $this->policy());
    }

    public static function policies(): array
    {
        $cases = [];
        foreach (Credit::cases() as $credit) {
            foreach (Gpa::cases() as $gpa) {
                foreach (Transfer::cases() as $transfer) {
                    $cases[$credit->value . ":" . $gpa->value . ":" . $transfer->value] = [
                        $credit,
                        $gpa,
                        $transfer,
                    ];
                }
            }
        }
        return $cases;
    }

    #[DataProvider("policies")]
    public function testEveryRetainedPolicyCombinationHasIndependentCreditAndGpaEffects(
        Credit $credit,
        Gpa $gpa,
        Transfer $transfer,
    ): void {
        $work = [
            $this->attempt(10, credits: 3000, points: 3000, date: "2026-05-01"),
            $this->attempt(11, credits: 4000, points: 4000, date: "2026-12-01"),
            $this->attempt(12, course: 2, credits: 2000, points: 2000, origin: "transfer"),
        ];
        $result = $this->evaluate($work, $this->policy($credit, $gpa, $transfer));
        self::assertSame($credit === Credit::HighestEarned ? 6000 : 9000, $result->earnedMilli);
        self::assertSame($credit === Credit::HighestEarned ? 4000 : 7000, $result->residentMinimumMilli);
        self::assertSame($result->residentMinimumMilli, $result->residentMaximumMilli);
        $credits = $gpa === Gpa::AllEligible ? 7000 : 4000;
        $quality = $gpa === Gpa::AllEligible ? 25000 : 16000;
        if ($transfer === Transfer::IncludeEligible) {
            $credits += 2000;
            $quality += 4000;
        }
        self::assertSame($credits, $result->gpa?->creditsMilli);
        self::assertSame($quality, $result->gpa?->qualityMilli);
        self::assertSame([], $result->issues);
    }

    public function testHighestGpaComparesGradePointsNotWeightedQualityPointsOrIdentityOrder(): void
    {
        $a = $this->attempt(90, credits: 1000, points: 4000, date: "2025-05-01");
        $b = $this->attempt(10, credits: 4000, points: 3000, date: "2026-05-01");
        $highest = $this->evaluate([$b, $a], $this->policy(gpa: Gpa::HighestGrade));
        self::assertSame(4000, $highest->gpa?->milli());
        self::assertSame([[self::id(90)->value]], $highest->gpaGroups[0]["options"]);
        $latest = $this->evaluate([$a, $b], $this->policy(gpa: Gpa::LatestEligible));
        self::assertSame(3000, $latest->gpa?->milli());
        self::assertSame([[self::id(10)->value]], $latest->gpaGroups[0]["options"]);
    }

    public function testEqualCreditAlternativesDoNotInventAResidencyDecisionOrDoubleTheAward(): void
    {
        $result = $this->evaluate([$this->attempt(10), $this->attempt(11, origin: "transfer")]);
        self::assertSame(3000, $result->earnedMilli);
        self::assertSame(0, $result->residentMinimumMilli);
        self::assertSame(3000, $result->residentMaximumMilli);
        self::assertCount(2, $result->creditPools[0]["options"]);
        self::assertSame(3000, $result->gpa?->creditsMilli);
        self::assertSame("transfer_excluded", $result->dispositions[1]["gpa"]);
    }

    public function testEqualGpaContributionsRemainAlternativesCountedOnce(): void
    {
        $result = $this->evaluate(
            [$this->attempt(10), $this->attempt(11)],
            $this->policy(gpa: Gpa::HighestGrade),
        );
        self::assertSame(3000, $result->gpa?->creditsMilli);
        self::assertSame(12000, $result->gpa?->qualityMilli);
        self::assertCount(2, $result->gpaGroups[0]["options"]);
        self::assertSame("equivalent_gpa_alternative", $result->dispositions[0]["gpa"]);
    }

    public function testUnequalTiedGpaContributionsAreUndeterminedInsteadOfChoosingAnId(): void
    {
        foreach ([Gpa::HighestGrade, Gpa::LatestEligible] as $policy) {
            $result = $this->evaluate(
                [$this->attempt(10), $this->attempt(11, credits: 4000)],
                $this->policy(gpa: $policy),
            );
            self::assertNull($result->gpa);
            self::assertSame([], $result->gpaGroups);
            self::assertSame("ambiguous_repeat", $result->issues[0]["code"]);
            self::assertSame(4000, $result->earnedMilli);
        }
    }

    public function testMissingCompletionDatesNeverBecomeUlidOrPostingChronology(): void
    {
        $result = $this->evaluate(
            [$this->attempt(10, date: null), $this->attempt(99)],
            $this->policy(gpa: Gpa::LatestEligible),
        );
        self::assertNull($result->gpa);
        self::assertSame("chronology_unknown", $result->issues[0]["code"]);
        $only = $this->evaluate([$this->attempt(10, date: null)], $this->policy(gpa: Gpa::LatestEligible));
        self::assertSame(4000, $only->gpa?->milli());
    }

    public function testCompletionDateCannotSubstituteForAnExplicitAcademicOrderingDate(): void
    {
        $a = $this->attempt(10, points: 4000, date: "2026-05-01");
        $b = $this->attempt(11, points: 3000, date: "2026-12-01");
        $withoutOrdering = new PolicyAttempt(
            $a->id,
            $a->courseId,
            $a->origin,
            $a->status,
            $a->attemptedMilli,
            $a->earnedMilli,
            $a->qualityMilli,
            true,
            $a->grade,
            "2027-03-01",
        );
        self::assertNull(
            $this->evaluate([$withoutOrdering, $b], $this->policy(gpa: Gpa::LatestEligible))->gpa,
        );
        $olderPeriodFinishedLater = new PolicyAttempt(
            $a->id,
            $a->courseId,
            $a->origin,
            $a->status,
            $a->attemptedMilli,
            $a->earnedMilli,
            $a->qualityMilli,
            true,
            $a->grade,
            "2027-03-01",
            "2026-05-01",
        );
        $result = $this->evaluate([$olderPeriodFinishedLater, $b], $this->policy(gpa: Gpa::LatestEligible));
        self::assertSame(3000, $result->gpa?->milli());
    }

    public function testInstitutionsCanSelectCompletionOrderingWhileEarlierUnspecifiedOrderingStaysUnknown(): void
    {
        $a = $this->attempt(10, points: 4000, date: "2026-05-01");
        $laterCompletion = new PolicyAttempt(
            $a->id,
            $a->courseId,
            $a->origin,
            $a->status,
            $a->attemptedMilli,
            $a->earnedMilli,
            $a->qualityMilli,
            true,
            $a->grade,
            "2027-03-01",
            "2026-05-01",
        );
        $b = $this->attempt(11, points: 3000, date: "2026-12-01");
        $completionPolicy = new AuditCalculationPolicy(
            Credit::HighestEarned,
            Gpa::LatestEligible,
            Transfer::Exclude,
            self::id(100),
            RepeatAttemptOrder::CompletionDate,
        );
        self::assertSame(4000, $this->evaluate([$laterCompletion, $b], $completionPolicy)->gpa?->milli());
        $unknownPolicy = new AuditCalculationPolicy(
            Credit::HighestEarned,
            Gpa::LatestEligible,
            Transfer::Exclude,
            self::id(100),
            null,
        );
        $unknown = $this->evaluate([$laterCompletion, $b], $unknownPolicy);
        self::assertNull($unknown->gpa);
        self::assertSame("ordering_policy_unknown", $unknown->issues[0]["code"]);
        $old = AuditCalculationPolicy::fromRequirements([
            "definition" => [
                "policies" => [
                    "repeat_credit" => "highest_earned",
                    "repeat_gpa" => "latest_eligible",
                    "transfer_overall_gpa" => "exclude",
                ],
            ],
            "grade_scale" => ["version_id" => self::id(100)->value],
        ]);
        self::assertNull($old->attemptOrder);
        self::assertSame(
            RepeatAttemptOrder::AcademicPeriod,
            AuditCalculationPolicy::forNewSelection(
                Credit::HighestEarned,
                Gpa::LatestEligible,
                Transfer::Exclude,
                self::id(100),
            )->attemptOrder,
        );
    }

    public function testLateDropCanAffectGpaWithoutCreatingEarnedCredit(): void
    {
        $drop = $this->attempt(10, points: 1000, passes: true, status: "W");
        $result = $this->evaluate([$drop]);
        self::assertSame(0, $result->earnedMilli);
        self::assertSame([], $result->creditPools);
        self::assertSame(1000, $result->gpa?->milli());
        self::assertSame(3000, $result->gpa?->creditsMilli);
        self::assertSame("late_drop", $result->dispositions[0]["credit"]);
        $excluded = $this->evaluate([$this->attempt(11, points: 0, passes: false, gpa: false, status: "W")]);
        self::assertSame(0, $excluded->gpa?->creditsMilli);
        self::assertNull($excluded->gpa?->milli());
    }

    public function testFailingGradesContributeWhenTheirRetainedDefinitionCountsInGpa(): void
    {
        $result = $this->evaluate([$this->attempt(10, points: 0, passes: false)]);
        self::assertSame(0, $result->earnedMilli);
        self::assertSame(0, $result->gpa?->milli());
        self::assertSame(3000, $result->gpa?->creditsMilli);
    }

    public function testInProgressRemovedVoidedAndDefaultWithdrawalMarkersDoNotBecomeUnknownGrades(): void
    {
        $work = [];
        foreach (["A", "D", "V", "W"] as $i => $status) {
            $work[] = new PolicyAttempt(
                self::id(10 + $i),
                self::id(1),
                $status === "V" ? "transfer" : "institutional",
                $status,
                3000,
                0,
                0,
                false,
                null,
            );
        }
        $result = $this->evaluate($work);
        self::assertSame([], $result->issues);
        self::assertSame(0, $result->earnedMilli);
        self::assertSame(0, $result->gpa?->creditsMilli);
    }

    public function testUnknownGradesSuppressPartialTotalsAndCannotBecomeZeroGpa(): void
    {
        $unknown = new PolicyAttempt(
            self::id(11),
            self::id(2),
            "institutional",
            "A",
            3000,
            3000,
            9000,
            true,
            null,
        );
        $result = $this->evaluate([$this->attempt(10), $unknown]);
        self::assertNull($result->earnedMilli);
        self::assertNull($result->gpa);
        self::assertSame([], $result->creditPools);
        self::assertSame([], $result->gpaGroups);
        self::assertSame("undetermined", $result->dispositions[0]["gpa"]);
        self::assertSame("grade_unknown", $result->dispositions[1]["gpa"]);
    }

    public function testTransferExclusionPrecedesGradeAndScaleUnknownsForGpaOnly(): void
    {
        $transfer = new PolicyAttempt(
            self::id(11),
            self::id(2),
            "transfer",
            "A",
            3000,
            3000,
            9000,
            true,
            null,
        );
        $result = $this->evaluate([$this->attempt(10), $transfer]);
        self::assertSame(4000, $result->gpa?->milli());
        self::assertNull($result->earnedMilli);
        $included = $this->evaluate([$transfer], $this->policy(transfer: Transfer::IncludeEligible));
        self::assertNull($included->gpa);
    }

    public function testUnknownAndDifferentGradePublicationsAreNeverConverted(): void
    {
        foreach ([null, 999] as $scale) {
            $result = $this->evaluate([$this->attempt(10, scale: $scale)]);
            self::assertNull($result->gpa);
            self::assertSame(3000, $result->earnedMilli);
        }
        $default = $this->evaluate([$this->attempt(10)], $this->policy(scale: null));
        self::assertNull($default->gpa);
        $noGpa = $this->evaluate([$this->attempt(10, gpa: false, scale: null)], $this->policy(scale: null));
        self::assertSame(0, $noGpa->gpa?->creditsMilli);
    }

    public function testMissingRetainedPolicyChoicesStayUnknownEvenForEmptyInputs(): void
    {
        $policy = AuditCalculationPolicy::fromRequirements(["definition" => []]);
        $result = $this->evaluate([], $policy);
        self::assertNull($result->earnedMilli);
        self::assertNull($result->gpa);
        self::assertCount(2, $result->issues);
    }

    public function testUnknownPolicyComponentsDoNotEraseIndependentKnownCalculations(): void
    {
        $work = [$this->attempt(10)];
        $noCreditPolicy = $this->evaluate($work, $this->policy(credit: null));
        self::assertNull($noCreditPolicy->earnedMilli);
        self::assertSame(4000, $noCreditPolicy->gpa?->milli());
        $noGpaPolicy = $this->evaluate($work, $this->policy(gpa: null));
        self::assertSame(3000, $noGpaPolicy->earnedMilli);
        self::assertNull($noGpaPolicy->gpa);
    }

    public function testOtherRequirementSettingsCannotInventOverallAwardsOrGpa(): void
    {
        $base = [
            "definition" => [
                "policies" => [
                    "repeat_credit" => "highest_earned",
                    "repeat_gpa" => "all_eligible",
                    "transfer_overall_gpa" => "exclude",
                    "group_reuse" => "one_group",
                    "waiver" => "waive_required_course_everywhere",
                ],
            ],
            "grade_scale" => ["version_id" => self::id(100)->value],
        ];
        $policy = AuditCalculationPolicy::fromRequirements($base);
        $base["definition"]["policies"]["group_reuse"] = "multiple_groups";
        $base["definition"]["groups"] = [["waived_course_ids" => [self::id(1)->value]]];
        $changed = AuditCalculationPolicy::fromRequirements($base);
        self::assertEquals(
            $this->evaluate([$this->attempt(10)], $policy),
            $this->evaluate([$this->attempt(10)], $changed),
        );
    }

    public function testUnsupportedPolicyTextIsRejectedRatherThanDefaulted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditCalculationPolicy::fromRequirements([
            "definition" => ["policies" => ["repeat_gpa" => "current_setting"]],
        ]);
    }

    public function testZeroCreditCompletionRetainsItsCourseEvidenceWithoutGpaWeight(): void
    {
        $result = $this->evaluate([$this->attempt(10, credits: 0)]);
        self::assertSame(0, $result->earnedMilli);
        self::assertCount(1, $result->creditPools);
        self::assertSame([self::id(10)->value], $result->creditPools[0]["options"][0]["work_ids"]);
        self::assertNull($result->gpa?->milli());
    }

    public function testFractionalAwardArithmeticAndCanonicalOrderRemainStable(): void
    {
        $work = [
            $this->attempt(10, credits: 3125, points: 3300),
            $this->attempt(11, credits: 3000, points: 2700),
        ];
        $policy = $this->policy(Credit::AllEligible, Gpa::AllEligible);
        $expected = $this->evaluate($work, $policy);
        self::assertSame(6125, $expected->earnedMilli);
        self::assertSame(18412, $expected->gpa?->qualityMilli);
        self::assertEquals($expected, $this->evaluate(array_reverse($work), $policy));
    }

    public function testGpaMinimumComparisonDoesNotRoundUpAndHandlesLargeIntegerRemainders(): void
    {
        $gpa = new GpaTotals(3999, 2000);
        self::assertSame(1999, $gpa->milli());
        self::assertFalse($gpa->meetsMinimum(2000));
        self::assertTrue($gpa->meetsMinimum(1999));
        self::assertNull(new GpaTotals(0, 0)->meetsMinimum(0));
        self::assertSame(999, new GpaTotals(PHP_INT_MAX - 1, PHP_INT_MAX)->milli());
        self::assertSame(1999, new GpaTotals(PHP_INT_MAX, intdiv(PHP_INT_MAX, 2) + 1)->milli());
    }

    public function testGroupEligibilityMaySelectALowerAwardWithoutFilteringOverallGpa(): void
    {
        $lowerGrade = $this->attempt(10, credits: 4000, points: 1000);
        $higherGrade = $this->attempt(11, credits: 3000, points: 3000);
        $overall = $this->evaluate([$lowerGrade, $higherGrade]);
        self::assertSame(4000, $overall->earnedMilli);
        self::assertSame(13000, $overall->gpa?->qualityMilli);
        $qualifiedForGroup = $this->evaluate([$higherGrade]);
        self::assertSame(3000, $qualifiedForGroup->earnedMilli);
        self::assertSame(13000, $overall->gpa?->qualityMilli);
    }

    public function testExactGpaDivisionAgreesWithIndependentSmallIntegerFractions(): void
    {
        foreach (range(1, 31) as $credits) {
            foreach (range(0, $credits * 10) as $quality) {
                self::assertSame(
                    intdiv($quality * 1000, $credits),
                    new GpaTotals($quality, $credits)->milli(),
                );
            }
        }
    }

    public function testDuplicateRecordIdentityCannotCountAnEarlierAndCorrectedGradeAsTwoAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate([$this->attempt(10), $this->attempt(10, points: 3000)]);
    }

    public function testInputLimitIsExplicitAndNeverSilentlyTruncatesRecords(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate(array_fill(0, 1001, $this->attempt(10)));
    }

    public function testThousandRetainedRecordsUseEachIdentityOnceWithoutGroupMultiplication(): void
    {
        $work = [];
        foreach (range(1, 1000) as $id) {
            $work[] = $this->attempt(10000 + $id);
        }
        $result = $this->evaluate($work, $this->policy(Credit::AllEligible));
        self::assertSame(3000000, $result->earnedMilli);
        self::assertSame(12000000, $result->gpa?->qualityMilli);
        self::assertSame(3000000, $result->gpa?->creditsMilli);
        self::assertCount(1000, $result->gpaGroups[0]["options"][0]);
    }

    public function testInconsistentWeightedPointsAndInvalidDatesAreRejected(): void
    {
        $a = $this->attempt(10);
        try {
            new PolicyAttempt(
                $a->id,
                $a->courseId,
                $a->origin,
                $a->status,
                3000,
                3000,
                4000,
                true,
                $a->grade,
            );
            self::fail("Per-grade points were confused with weighted quality points.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->attempt(11, date: "2026-02-30");
    }
}
