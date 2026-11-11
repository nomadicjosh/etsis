<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use DateTimeImmutable;
use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use StudentRequirementsFixture;
use TransferAwardFixture;

final class AcademicInputVerificationTest extends TestCase
{
    private function service(
        StudentRequirementsFixture $f,
        ?AcademicInputRepository $inputs = null,
    ): AcademicInputService {
        $s = $f->f->sis;
        return new AcademicInputService(
            $inputs ?? new SqlAcademicInputRepository($s->sql),
            $s->academicCredits(),
            $s->transferCredits(),
            $s->enrollmentLedger(),
            new SqlStudentRecordRepository($s->sql),
            $f->service,
            $s->transactions,
        );
    }

    private function registered(StudentRequirementsFixture $f): array
    {
        $s = $f->f->sis;
        $section = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $credit = new EntityId(
            $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        return [$section, $enrollment, $credit];
    }

    public function testChecksRegistrationAndAcademicFactsWithoutReadSideWritesOrInventingCompletion(): void
    {
        $f = new StudentRequirementsFixture();
        [, $enrollment, $credit] = $this->registered($f);
        $s = $f->f->sis;
        $before = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $result = $this->service($f)->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::Verified, $result->status);
        self::assertCount(1, $result->items);
        self::assertSame($credit->value, $result->items[0]["id"]);
        self::assertNull($result->items[0]["grade"]);
        self::assertSame(0, $result->items[0]["completed_milli"]);
        self::assertSame($enrollment->value, $result->references[0]["source_id"]);
        self::assertSame(1, $result->references[0]["registration_version"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
    }

    public function testEarlyRemovalIsVerifiedInternallyAndNeverReturnedAsCurrentWorkWhileLateDropRemains(): void
    {
        foreach ([false, true] as $late) {
            $f = new StudentRequirementsFixture();
            [$section, , $credit] = $this->registered($f);
            $s = $f->f->sis;
            $s->legacyGrade(new GradeDefinition(EntityId::generate(), "W", 0, false, false));
            if ($late) {
                $s->clock->time = new DateTimeImmutable("2026-08-20T12:00:00Z");
            }
            $review = $s->drops->review($f->student, $section->id);
            $s->drops->commit(
                $f->student,
                $section->id,
                new EntityId($review["enrollment_id"]),
                $review["version"],
                new EntityId($review["operation_id"]),
                $review["revision"],
                $review["effective_on"],
            );
            $result = $this->service($f)->collect($f->student, $f->assignment);
            self::assertSame(AcademicInputStatus::Verified, $result->status);
            self::assertCount($late ? 1 : 0, $result->items);
            self::assertSame(!$late, $result->references[0]["removed"]);
            self::assertSame($credit->value, $result->references[0]["credit_id"]);
            if ($late) {
                self::assertSame("W", $result->items[0]["status"]);
            }
        }
    }

    public function testSurvivingHistoryReferenceDetectsMissingProjectionWithoutReturningAnyPartialInputs(): void
    {
        $f = new StudentRequirementsFixture();
        $this->registered($f);
        [, , $credit] = $this->registered($f);
        $s = $f->f->sis;
        $s->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$credit->value]);
        $result = $this->service($f)->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::Inconsistent, $result->status);
        self::assertSame([], $result->items);
        self::assertSame([], $result->references);
    }

    public function testMissingRegistrationProjectionOrAcademicMappingCannotPassVerification(): void
    {
        foreach (["sis_enrollments", "sis_academic_credit_streams", "sis_registration_streams"] as $table) {
            $f = new StudentRequirementsFixture();
            [, $enrollment, $credit] = $this->registered($f);
            $f->f->sis->sql->execute("DELETE FROM " . $table . " WHERE id = ?", [
                $table === "sis_academic_credit_streams" ? $credit->value : $enrollment->value,
            ]);
            self::assertSame(
                AcademicInputStatus::Inconsistent,
                $this->service($f)->collect($f->student, $f->assignment)->status,
                $table,
            );
        }
    }

    public function testDamagedProtectedEventProducesAnInconsistentResultAfterTheReadTransactionRollsBack(): void
    {
        $f = new StudentRequirementsFixture();
        [, , $credit] = $this->registered($f);
        $s = $f->f->sis;
        $head = $s->sql->one("sis_history_streams", [
            "stream_type" => "academic_credit",
            "aggregate_id" => $credit->value,
        ]);
        $s->sql->update(
            "sis_history_events",
            ["event_hash" => str_repeat("0", 64)],
            ["stream_id" => $head["id"]],
        );
        self::assertSame(
            AcademicInputStatus::Inconsistent,
            $this->service($f)->collect($f->student, $f->assignment)->status,
        );
    }

    public function testOlderLinkedCurrentFactsRemainUnknownInsteadOfCreatingHistory(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $section = $s->section();
        $enrollment = EntityId::generate();
        $s->sql->insert("sis_enrollments", [
            "id" => $enrollment->value,
            "student_id" => $f->student->value,
            "section_id" => $section->id->value,
            "term_id" => $section->termId->value,
            "status" => "A",
            "registered_at" => "2026-08-01 12:00:00",
            "status_at" => "2026-08-01 12:00:00",
            "version" => 0,
        ]);
        $s->sql->insert("sis_academic_credits", [
            "id" => EntityId::generate()->value,
            "student_id" => $f->student->value,
            "course_id" => $section->courseId->value,
            "term_id" => $section->termId->value,
            "source_id" => $enrollment->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Fictional older course",
            "attempted_milli" => 3000,
            "completed_milli" => 3000,
            "grade_points_milli" => 9000,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        $before = $s->sql->rows("sis_history_events");
        $result = $this->service($f)->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::HistoryGaps, $result->status);
        self::assertTrue($result->items[0]["earlier_history_unknown"]);
        self::assertNull($result->items[0]["grade"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testRetainedTransferAndVoidedAwardsAreCheckedAndMissingAwardsAreRejected(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $t = new TransferAwardFixture($s, $f->f->catalog->fixture->cipher, $f->student);
        $service = $this->service($f);
        $result = $service->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::Verified, $result->status);
        self::assertSame("TR", $result->items[0]["grade"]["code"]);
        $change = new AwardChange("void", null, null, "Fictional duplicate award.");
        $plan = $t->service()->preview($t->student, $t->award, $change);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $change, $plan["revision"]);
        $result = $service->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::Verified, $result->status);
        self::assertSame("V", $result->items[0]["status"]);
        self::assertNull($result->items[0]["grade"]);
        // Simulate a damaged source row only in the fictional in-memory SQLite database.
        $s->sql->execute("PRAGMA foreign_keys = OFF");
        try {
            $s->sql->execute("DELETE FROM sis_transfer_awards WHERE id = ?", [$t->award->value]);
        } finally {
            $s->sql->execute("PRAGMA foreign_keys = ON");
        }
        self::assertSame(
            AcademicInputStatus::Inconsistent,
            $service->collect($f->student, $f->assignment)->status,
        );
    }

    public function testCumulativeReplayLimitNeverReturnsPartialInputs(): void
    {
        $f = new StudentRequirementsFixture();
        $this->registered($f);
        $result = $this->service($f)->collect(
            $f->student,
            $f->assignment,
            new HistoryReadBudget(maximumPages: 1),
        );
        self::assertSame(AcademicInputStatus::Limited, $result->status);
        self::assertSame([], $result->items);
        self::assertSame([], $result->references);
    }

    public function testSourceQueryCapsTheRecordsBeforeAnyReplay(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $section = $s->section();
        for ($i = 0; $i <= AcademicInputInventory::LIMIT; $i++) {
            $s->sql->insert("sis_academic_credits", [
                "id" => EntityId::generate()->value,
                "student_id" => $f->student->value,
                "course_id" => $section->courseId->value,
                "term_id" => $section->termId->value,
                "source_id" => EntityId::generate()->value,
                "credit_type" => "institutional",
                "course_code" => "OLD.101",
                "course_title" => "Fictional older work",
                "attempted_milli" => 3000,
                "completed_milli" => 0,
                "grade_points_milli" => 0,
                "grade_id" => null,
                "status" => "A",
                "version" => 0,
            ]);
        }
        $result = $this->service($f)->collect($f->student, $f->assignment);
        self::assertSame(AcademicInputStatus::Limited, $result->status);
        self::assertSame([], $result->items);
    }

    public function testOwnerAndAcademicReaderScopeAreCheckedAndAnotherPursuitIsRejected(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $s->access->staff = false;
        self::assertSame(
            AcademicInputStatus::Verified,
            $this->service($f)->collect($f->student, $f->assignment)->status,
        );
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        self::assertSame(
            AcademicInputStatus::Verified,
            $this->service($f)->collect($f->student, $f->assignment)->status,
        );
        try {
            $this->service($f)->collect($f->student, EntityId::generate());
            self::fail("Another pursuit was accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $s->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $this->service($f)->collect($f->student, $f->assignment);
    }

    public function testAuthorityIsRecheckedBeforeReturningEvenAnEmptyInputSet(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $s->access->staff = false;
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        $inputs = new class ($s) implements AcademicInputRepository {
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): AcademicInputInventory
            {
                $inventory = new SqlAcademicInputRepository($this->s->sql)->inventory($student);
                $this->s->access->grants = [];
                return $inventory;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $inputs)->collect($f->student, $f->assignment);
    }
}
