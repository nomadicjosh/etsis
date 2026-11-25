<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\GraduateRegistrationFixture;

final class GraduateRegistrationTest extends TestCase
{
    private function fixture(): GraduateRegistrationFixture
    {
        require_once dirname(__DIR__) . "/Support/GraduateRegistrationFixture.php";
        return new GraduateRegistrationFixture();
    }

    public function testExactMinimumGpaStillRequiresBothIndependentApprovals(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        try {
            $s->service->register($f->student, $f->target->section->id, true);
            self::fail("Graduate registration bypassed its approvals.");
        } catch (ValidationException) {
            self::assertNull($s->registration->enrollment($f->student, $f->target->section->id));
        }
        $f->decide("advisor_approve");
        self::assertFalse(
            $s->graduateApprovals()->view($f->student, $f->target->section->id)["checks"][2]["status"] ===
                "met",
        );
        $f->decide("registrar_override");
        $accepted = $s->service->register($f->student, $f->target->section->id, true);
        self::assertTrue($accepted->status->isEnrolled());
    }
    public function testAboveExactGpaFloorCannotBeWaivedAndDoesNotWriteDecisions(): void
    {
        $f = $this->fixture();
        $f->policy(gpaMinimum: 2001);
        $s = $f->sis;
        $before = \EventStoreFixture::historyDigest($s->sql);
        foreach (["advisor_approve", "registrar_override"] as $action) {
            $s->access->actor = str_starts_with($action, "advisor_") ? $f->advisorUser : $f->registrar;
            try {
                $s->graduateApprovals()->review(
                    $f->student,
                    $f->target->section->id,
                    $f->pursuit,
                    $action,
                    0,
                    "Fictional request below GPA floor.",
                );
                self::fail("A hard GPA minimum was waived.");
            } catch (\DomainException $failure) {
                self::assertStringContainsString("GPA", $failure->getMessage());
            }
        }
        $s->access->actor = $f->registrar;
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $review = $s
            ->registrationBatches()
            ->review(
                new \Domain\Registration\ValueObject\RegistrationBatchSelection(
                    $f->target->section->id,
                    $f->student,
                ),
                true,
            );
        $checks = array_column($review["students"][0]["readiness"], null, "code");
        self::assertSame("blocked", $checks["graduate_academic"]["status"]);
    }

    public function testLaterGpaAndPolicyChangesDoNotRemoveAcceptedEnrollmentOrPreventFinalCredit(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $accepted = $s->service->register($f->student, $f->target->section->id, true);
        $other = $f->offering($f->undergraduate);
        $failed = $s->service->register($f->student, $other->section->id, true);
        $f->grade($other, $failed->id, "F");
        $f->policy(gradeMinimum: 4000, gpaMinimum: 4000);
        $credit = $f->grade($f->target, $accepted->id, "A");
        self::assertSame(
            3000,
            (int) $s->sql->one("sis_academic_credits", ["id" => $credit->value])["completed_milli"],
        );
        $before = \EventStoreFixture::historyDigest($s->sql);
        self::assertSame(
            $accepted->id->value,
            $s->service->register($f->student, $f->target->section->id, true)->id->value,
        );
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testNewPolicyRequiresFreshApprovalsAndAdvisorRevocationClearsRegistrarDecision(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $f->policy();
        $checks = $s->graduateApprovals()->view($f->student, $f->target->section->id)["checks"];
        self::assertSame(["met", "blocked", "blocked"], array_column($checks, "status"));
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $f->decide("advisor_revoke");
        $view = $s->graduateApprovals()->view($f->student, $f->target->section->id);
        self::assertFalse($view["advisor_recorded"]);
        self::assertFalse($view["registrar_recorded"]);
        self::assertSame(5, $view["version"]);
    }

    public function testAnEarlierFailedGraduateCourseStillBlocksAfterAMoreRecentPassingCourse(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $first = $s->service->register($f->student, $f->target->section->id, true);
        $f->grade($f->target, $first->id, "F");
        $other = $f->offering($f->undergraduate);
        $work = $s->service->register($f->student, $other->section->id, true);
        $f->grade($other, $work->id, "A");
        $later = $f->offering($f->graduate);
        $f->decide("advisor_approve", $later);
        $f->decide("registrar_override", $later);
        $work = $s->service->register($f->student, $later->section->id, true);
        $f->grade($later, $work->id, "A");
        $f->policy(gradeMinimum: 2000);
        $target = $f->offering($f->graduate);
        $view = $s->graduateApprovals()->view($f->student, $target->section->id);
        self::assertStringContainsString("Every completed graduate course", $view["checks"][0]["message"]);
        self::assertSame("blocked", $view["checks"][0]["status"]);
        self::assertNull($s->registration->enrollment($f->student, $target->section->id));
    }

    public function testFirstGraduateCourseDoesNotNeedAnEarlierGraduateGrade(): void
    {
        $f = $this->fixture();
        $f->policy(gradeMinimum: 4000);
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        self::assertTrue(
            $f->sis->service->register($f->student, $f->target->section->id, true)->status->isEnrolled(),
        );
    }

    public function testOnlyAnAssignedAdvisorCanApproveEvenWithTheSpecificPermission(): void
    {
        $f = $this->fixture();
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $f->sis
            ->graduateApprovals()
            ->review(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                "advisor_approve",
                0,
                "Fictional unassigned request.",
            );
    }

    public function testRequiredAuditFailureRollsBackDecisionAndCounter(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->access->actor = $f->advisorUser;
        $service = $s->graduateApprovals();
        $r = $service->review(
            $f->student,
            $f->target->section->id,
            $f->pursuit,
            "advisor_approve",
            0,
            "Fictional approval.",
        );
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willThrowException(new \RuntimeException("Fictional audit failure."));
        $before = \EventStoreFixture::historyDigest($s->sql);
        try {
            $s->graduateApprovals($audit)->confirm(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["version"],
                $r["reason"],
                $r["revision"],
                $r["effective_at"],
                $r["review_token"],
            );
            self::fail("A decision survived a failed required audit.");
        } catch (\RuntimeException $failure) {
            self::assertSame("Fictional audit failure.", $failure->getMessage());
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        self::assertSame([], $s->sql->rows("sis_graduate_course_approvals"));
    }

    public function testApprovalReviewExpiresAndAcceptedRetrySurvivesLaterPolicyChanges(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $r = $f->decide("advisor_approve");
        $f->policy();
        $s->access->actor = $f->advisorUser;
        $s->clock->time = $s->clock->time->modify("+1 day");
        $before = \EventStoreFixture::historyDigest($s->sql);
        $result = $s
            ->graduateApprovals()
            ->confirm(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["version"],
                $r["reason"],
                $r["revision"],
                $r["effective_at"],
                $r["review_token"],
            );
        self::assertSame($r["operation_id"], $result["operation_id"]);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $fresh = $s
            ->graduateApprovals()
            ->review(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                "advisor_approve",
                1,
                "Fictional expiring approval.",
            );
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $s->graduateApprovals()->confirm(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                new EntityId($fresh["operation_id"]),
                $fresh["action"],
                $fresh["version"],
                $fresh["reason"],
                $fresh["revision"],
                $fresh["effective_at"],
                $fresh["review_token"],
            );
            self::fail("An expired unaccepted decision was recorded.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
    }

    public function testAChangedGpaInvalidatesPendingApprovalAndRevocationStillWorksBelowTheFloor(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $s->access->actor = $f->registrar;
        $r = $s
            ->graduateApprovals()
            ->review(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                "registrar_override",
                1,
                "Fictional pending override.",
            );
        $other = $f->offering($f->undergraduate);
        $enrollment = $s->service->register($f->student, $other->section->id, true);
        $f->grade($other, $enrollment->id, "F");
        $before = \EventStoreFixture::historyDigest($s->sql);
        try {
            $s->graduateApprovals()->confirm(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["version"],
                $r["reason"],
                $r["revision"],
                $r["effective_at"],
                $r["review_token"],
            );
            self::fail("A pending approval ignored the changed GPA.");
        } catch (\DomainException) {
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
        $f->decide("advisor_revoke");
        self::assertFalse(
            $s->graduateApprovals()->view($f->student, $f->target->section->id)["advisor_recorded"],
        );
    }

    public function testPolicyChangeAtTheFinalRegistrationAuditRollsBackEveryEffect(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $before = \EventStoreFixture::historyDigest($s->sql);
        $version = $s->graduatePolicies()->view()["version"];
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::atLeastOnce())
            ->method("record")
            ->willReturnCallback(static function ($actor, $action, $subject) use ($f): void {
                if ($action === "registration.created") {
                    $f->policy(gpaMinimum: 3000);
                }
            });
        try {
            $s->courseRegistrationService($audit)->register($f->student, $f->target->section->id, true);
            self::fail("A final policy change left an enrollment behind.");
        } catch (ValidationException) {
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
            self::assertNull($s->registration->enrollment($f->student, $f->target->section->id));
            self::assertSame($version, $s->graduatePolicies()->view()["version"]);
        }
    }

    public function testDamagedApprovalCounterBlocksRatherThanInventingMissingDecisions(): void
    {
        $f = $this->fixture();
        $f->decide("advisor_approve");
        $f->sis->sql->execute("DELETE FROM sis_graduate_course_approvals");
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->sis->graduateApprovals()->view($f->student, $f->target->section->id);
    }

    public function testSharedGroupAndScheduleReviewCannotBypassGraduateApproval(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $f->target->section->id,
            $f->student,
        );
        $service = $s->registrationBatches();
        $review = $service->review($selection, true);
        self::assertNotEmpty($review["students"][0]["issues"]);
        $before = \EventStoreFixture::historyDigest($s->sql);
        try {
            $service->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("Group entry bypassed graduate-course approval.");
        } catch (ValidationException) {
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
        $plan = $service->reviewPlan(
            new \Domain\Registration\ValueObject\RegistrationPlanSelection(
                $f->student,
                $f->target->section->id,
            ),
            true,
        );
        self::assertFalse($plan["can_confirm"]);
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $review = $service->review($selection, true);
        $result = $service->commit(
            new EntityId($review["operation_id"]),
            $selection,
            $review["revision"],
            $review["review_token"],
        );
        self::assertSame(1, $result["created"]);
        self::assertTrue(
            $s->registration->enrollment($f->student, $f->target->section->id)->status->isEnrolled(),
        );
    }
    public function testReinstatementRetainsAndAuthenticatesNewGraduateEligibilityEvidence(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $enrollment = $s->service->register($f->student, $f->target->section->id, true);
        $s->publishDropPolicy(
            $s->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([["through" => null, "grade_code" => "W"]]),
        );
        $s->clock->time = new \DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $drop = $s->drops->review($f->student, $f->target->section->id);
        $s->drops->commit(
            $f->student,
            $f->target->section->id,
            $enrollment->id,
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        $service = $s->reinstatements();
        $review = $service->review(
            $f->student,
            $f->target->section->id,
            $enrollment->id,
            "Fictional reinstatement.",
        );
        self::assertTrue($review["can_confirm"]);
        $done = $service->confirm(
            $f->student,
            $f->target->section->id,
            $enrollment->id,
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["reason"],
            $review["review_token"],
        );
        self::assertSame($enrollment->id->value, $done["enrollment_id"]);
        $s->enrollmentLedger()->verifyExisting(
            $s->registration->enrollment($f->student, $f->target->section->id),
            $s->registration->section($f->target->section->id),
        );
        self::assertTrue(
            $s->registration->enrollment($f->student, $f->target->section->id)->status->isEnrolled(),
        );
    }
    public function testCanonicalIdentityChangeDuringApprovalAuditRollsBackTheDecisionAndIdentityEdit(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $originalPerson = $s->admissions->student($f->student)->personId->value;
        $other = $s->legacyPerson($s->user(), 'Fictional', 'Unrelated');
        $s->access->actor = $f->advisorUser;
        $r = $s->graduateApprovals()->review(
            $f->student,
            $f->target->section->id,
            $f->pursuit,
            'advisor_approve',
            0,
            'Fictional identity boundary.'
        );
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function () use ($s, $f, $other): void {
                $s->sql->update('sis_students', ['person_id' => $other->id->value], ['id' => $f->student->value]);
            }
        );
        $before = \EventStoreFixture::historyDigest($s->sql);
        try {
            $s->graduateApprovals($audit)->confirm(
                $f->student,
                $f->target->section->id,
                $f->pursuit,
                new EntityId($r['operation_id']),
                $r['action'],
                $r['version'],
                $r['reason'],
                $r['revision'],
                $r['effective_at'],
                $r['review_token']
            );
            self::fail('An approval accepted a changed canonical identity.');
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($originalPerson, $s->admissions->student($f->student)->personId->value);
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
    }
}
