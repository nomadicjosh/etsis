<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Service\AcademicUpdatesService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use Infrastructure\Persistence\Repository\SqlAcademicUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AcademicUpdatesTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $enrollment = $f->service->register($student->id, $section->id, true);
        $grades = array_column(
            $f->academicCredits()->choices($student->id, $section->id, $enrollment->id)["items"],
            "id",
            "code",
        );
        return [$f, $student, $section, $enrollment, $grades];
    }

    private function updates(SisFixture $f, ?AuditTrail $audit = null): AcademicUpdatesService
    {
        return new AcademicUpdatesService(
            new SqlAcademicUpdatesRepository($f->sql),
            new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator()),
            $f->admissions,
            $f->academicCredits(),
            new SqlAcademicCreditRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }

    private function post(array $fixture, string $code = "B", int $version = 0): array
    {
        [$f, $student, $section, $enrollment, $grades] = $fixture;
        $review = $f
            ->academicCredits()
            ->review(
                $student->id,
                $section->id,
                $enrollment->id,
                new EntityId($grades[$code]),
                $version,
                reason: "Fictional academic decision",
            );
        return [$review, $f->academicCredits()->commit($student->id, $section->id, $review["review_token"])];
    }

    public function testOnlyAcceptedGradesNotifyAndOwnerCanReadCurrentVerifiedRecordAndDeletePermanently(): void
    {
        $fixture = [$f, $student, $section] = $this->fixture();
        self::assertSame([], $f->sql->rows("sis_academic_updates"));
        [$review, $receipt] = $this->post($fixture);
        $row = $f->sql->rows("sis_academic_updates")[0];
        self::assertArrayNotHasKey("grade", $row);
        self::assertArrayNotHasKey("reason", $row);
        $staff = $f->access->actor;
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $updates = $this->updates($f);
        $page = $updates->page();
        self::assertCount(1, $page["items"]);
        self::assertSame("posted", $page["items"][0]["status"]);
        self::assertArrayNotHasKey("student_id", $page["items"][0]);
        self::assertSame("B", $updates->detail(new EntityId($row["id"]))["grade"]);
        $updates->read(new EntityId($row["id"]), (int) $row["revision"]);
        self::assertSame([], $updates->page()["items"]);
        self::assertCount(1, $updates->page(false)["items"]);
        $updates->delete(new EntityId($row["id"]), (int) $row["revision"]);
        self::assertSame([], $f->sql->rows("sis_academic_updates"));
        $f->access->actor = $staff;
        $f->access->staff = true;
        $f->clock->time = $f->clock->now()->modify("+2 days");
        self::assertSame(
            $receipt,
            $f->academicCredits()->commit($student->id, $section->id, $review["review_token"]),
        );
        self::assertSame([], $f->sql->rows("sis_academic_updates"));
    }

    public function testPendingChangesDoNotNotifyButApprovalReplacesTheNotice(): void
    {
        $fixture = [$f, $student, $section] = $this->fixture();
        $this->post($fixture);
        $initial = $f->sql->rows("sis_academic_updates");
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        [, $receipt] = $this->post($fixture, "A", 1);
        self::assertSame($initial, $f->sql->rows("sis_academic_updates"));
        $f->access->actor = $f->user();
        $id = new EntityId($receipt["operation_id"]);
        $review = $ledger->reviewGradeChange($id, "approved", "Fictional independent approval");
        $accepted = $ledger->resolveGradeChange($id, $review["review_token"]);
        $rows = $f->sql->rows("sis_academic_updates");
        self::assertCount(1, $rows);
        self::assertNotSame($initial[0]["id"], $rows[0]["id"]);
        self::assertSame("corrected", $rows[0]["status"]);
        $f->sql->execute("DELETE FROM sis_academic_updates");
        self::assertSame($accepted, $ledger->resolveGradeChange($id, $review["review_token"]));
        self::assertSame([], $f->sql->rows("sis_academic_updates"));
    }

    public function testForeignAccountsAndEvenStaffCannotAccessAnotherPersonsNotification(): void
    {
        $fixture = [$f, $student] = $this->fixture();
        $this->post($fixture);
        $row = $f->sql->rows("sis_academic_updates")[0];
        $f->student(); // Canonical person for the unrelated current staff account.
        $updates = $this->updates($f);
        self::assertSame([], $updates->page(false)["items"]);
        $updates->delete(new EntityId($row["id"]), (int) $row["revision"]);
        self::assertCount(1, $f->sql->rows("sis_academic_updates"));
        $this->expectException(AccessDeniedException::class);
        $updates->detail(new EntityId($row["id"]));
    }

    public function testFinalGradeAuditFailureRollsBackTheNoticeGradeAndJournalTogether(): void
    {
        $fixture = [$f, $student, $section, $enrollment, $grades] = $this->fixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional required audit failure"));
        $ledger = $f->academicCredits($audit);
        $review = $ledger->review($student->id, $section->id, $enrollment->id, new EntityId($grades["A"]), 0);
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_academic_updates",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        try {
            $ledger->commit($student->id, $section->id, $review["review_token"]);
            self::fail("A failed grade audit committed.");
        } catch (\RuntimeException) {
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }

    public function testDeletingWithAnOldNoticeIdCannotRemoveANewerCorrection(): void
    {
        $fixture = [$f, $student] = $this->fixture();
        $this->post($fixture);
        $old = $f->sql->rows("sis_academic_updates")[0];
        $this->post($fixture, "A", 1);
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $this->updates($f)->delete(new EntityId($old["id"]), (int) $old["revision"]);
        self::assertCount(1, $f->sql->rows("sis_academic_updates"));
        self::assertSame("corrected", $f->sql->rows("sis_academic_updates")[0]["status"]);
    }
    public function testRejectedAndCancelledCorrectionsKeepTheExistingNoticeUnchanged(): void
    {
        foreach (["rejected", "cancelled"] as $outcome) {
            $fixture = [$f] = $this->fixture();
            $this->post($fixture);
            $before = $f->sql->rows("sis_academic_updates");
            $ledger = $f->academicCredits();
            $ledger->saveGradeChangePolicy("approval", 0);
            [, $receipt] = $this->post($fixture, "A", 1);
            if ($outcome === "rejected") {
                $f->access->actor = $f->user();
            }
            $id = new EntityId($receipt["operation_id"]);
            $review = $ledger->reviewGradeChange($id, $outcome, "Fictional correction disposition");
            $ledger->resolveGradeChange($id, $review["review_token"]);
            self::assertSame($before, $f->sql->rows("sis_academic_updates"));
        }
    }

    public function testFinalNoticeOrRecipientChangesRollbackTheCompleteGradeDecision(): void
    {
        foreach (["notice", "owner"] as $failure) {
            [$f, $student, $section, $enrollment, $grades] = $this->fixture();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(function () use ($f, $student, $failure): void {
                if ($failure === "notice") {
                    $f->sql->execute("DELETE FROM sis_academic_updates");
                } else {
                    $f->sql->update(
                        "sis_persons",
                        ["owner_user_id" => $f->access->actor->value],
                        ["id" => $student->personId->value],
                    );
                }
            });
            $ledger = $f->academicCredits($audit);
            $review = $ledger->review($student->id, $section->id, $enrollment->id, new EntityId($grades["A"]), 0);
            $tables = [
                "sis_persons",
                "sis_academic_credits",
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
            ];
            $before = array_map($f->sql->rows(...), $tables);
            try {
                $ledger->commit($student->id, $section->id, $review["review_token"]);
                self::fail("A changed notice or recipient committed a grade.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, array_map($f->sql->rows(...), $tables));
                self::assertSame([], $f->sql->rows("sis_academic_updates"));
            }
        }
    }

    public function testNotificationDeletionAuditFailurePreservesTheNotice(): void
    {
        $fixture = [$f, $student] = $this->fixture();
        $this->post($fixture);
        $row = $f->sql->rows("sis_academic_updates")[0];
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional required audit failure"));
        try {
            $this->updates($f, $audit)->delete(new EntityId($row["id"]), (int) $row["revision"]);
            self::fail("A failed required notice audit deleted the notice.");
        } catch (\RuntimeException) {
            self::assertSame([$row], $f->sql->rows("sis_academic_updates"));
        }
    }
}
