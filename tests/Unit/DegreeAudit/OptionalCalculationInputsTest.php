<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\ValueObject\AcademicPolicyResult;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\GpaTotals;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\PursuitStart;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class OptionalCalculationInputsTest extends TestCase
{
    public function testOmittedPolicyInputsStayUnknownRatherThanApplyingNewPolicyDefaults(): void
    {
        $policy = new AuditCalculationPolicy();
        self::assertNull($policy->credit);
        self::assertNull($policy->gpa);
        self::assertNull($policy->transferGpa);
        self::assertNull($policy->gradeScaleVersion);
        self::assertNull($policy->attemptOrder);
        $result = new AcademicPolicyEvaluator()->evaluate([], $policy);
        self::assertNull($result->earnedMilli);
        self::assertNull($result->gpa);
        self::assertCount(2, $result->issues);
    }

    public function testNewSelectionCanOmitItsUnconfiguredGradeScaleWithoutChangingItsOrderingDefault(): void
    {
        $policy = AuditCalculationPolicy::forNewSelection(
            RepeatCreditPolicy::HighestEarned,
            RepeatGpaPolicy::AllEligible,
            TransferGpaPolicy::Exclude,
        );
        self::assertNull($policy->gradeScaleVersion);
        self::assertSame(RepeatAttemptOrder::AcademicPeriod, $policy->attemptOrder);
    }

    public function testOptionalResultTotalsFollowTheRequiredContextAndRemainUnknown(): void
    {
        $result = new AcademicPolicyResult(
            policy: new AuditCalculationPolicy(),
            creditPools: [],
            gpaGroups: [],
            dispositions: [],
            issues: [
                ["component" => "credit", "code" => "policy_unknown", "course_id" => null, "work_ids" => []],
            ],
        );
        self::assertNull($result->earnedMilli);
        self::assertNull($result->residentMinimumMilli);
        self::assertNull($result->residentMaximumMilli);
        self::assertNull($result->gpa);
    }

    public function testExplicitZeroAndKnownNoGpaRemainDifferentFromOmittedTotals(): void
    {
        $result = new AcademicPolicyResult(
            policy: new AuditCalculationPolicy(),
            creditPools: [],
            gpaGroups: [],
            dispositions: [],
            issues: [],
            earnedMilli: 0,
            gpa: new GpaTotals(0, 0),
        );
        self::assertSame(0, $result->earnedMilli);
        self::assertInstanceOf(GpaTotals::class, $result->gpa);
        self::assertNull($result->gpa->milli());
        self::assertNull($result->residentMinimumMilli);
    }

    public function testAnUngradedAttemptCanOmitItsUnknownSnapshotAndDates(): void
    {
        $attempt = new PolicyAttempt(
            id: new EntityId("00000000000000000000000001"),
            courseId: new EntityId("00000000000000000000000002"),
            origin: "institutional",
            status: "A",
            attemptedMilli: 3000,
            earnedMilli: 0,
            qualityMilli: 0,
            gradeRecorded: false,
        );
        self::assertNull($attempt->grade);
        self::assertNull($attempt->completedOn);
        self::assertNull($attempt->academicOn);
    }

    public function testOmittedProgramLengthDoesNotInventAnAnticipatedCompletionDate(): void
    {
        self::assertNull(new PursuitStart("2026-01-31")->anticipatedCompletion());
        self::assertSame("2027-01-31", new PursuitStart("2026-01-31")->anticipatedCompletion(12));
    }
}
