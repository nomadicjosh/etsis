<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use SisFixture;
use HiatusFixture;
use TransferAwardFixture;
use DateTimeImmutable;

use Domain\DegreeAudit\Repository\AcademicWorkRepository;
use Domain\DegreeAudit\Service\AcademicWorkService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use Infrastructure\Persistence\Repository\SqlAcademicWorkRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class AcademicWorkTest extends TestCase
{
    private function service(SisFixture $f, ?AcademicWorkRepository $work = null): AcademicWorkService
    {
        return new AcademicWorkService(
            $work ?? new SqlAcademicWorkRepository($f->sql),
            $f->admissions,
            new SqlStudentRecordRepository($f->sql),
            $f->academicCredits(),
            $f->transferCredits(),
            $f->access,
            $f->transactions,
        );
    }

    private function assignment(SisFixture $f, EntityId $student): EntityId
    {
        return new EntityId(
            $f->sql->rows("sis_student_programs", ["student_id" => $student->value], 1)[0]["id"],
        );
    }

    private function institution(): array
    {
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
        ]);
        $student = $f->student()->id;
        $section = $f->section(faculty: $f->access->actor);
        $enrollment = $f->service->register($student, $section->id)->id;
        $credit = new EntityId(
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        return [$f, $student, $section, $enrollment, $credit];
    }

    public function testRetainedGradeAndWeightedPointsSurviveCurrentCatalogChangesWithoutReadSideWrites(): void
    {
        [$f, $student, $section, $enrollment] = $this->institution();
        $ledger = $f->academicCredits();
        $grade = new EntityId($ledger->choices($student, $section->id, $enrollment)["items"][0]["id"]);
        $review = $ledger->review($student, $section->id, $enrollment, $grade, 0);
        $ledger->commit($student, $section->id, $review["review_token"]);
        $f->sql->update("sis_courses", ["title" => "Changed today"], ["id" => $section->courseId->value]);
        $f->sql->update("sis_grades", ["points_milli" => 1000], ["id" => $grade->value]);
        $before = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $page = $this->service($f)->page($student, $this->assignment($f, $student));
        $item = $page["items"][0];
        self::assertSame("Computing", $item["course_title"]);
        self::assertSame(4000, $item["grade"]["points_milli"]);
        self::assertSame(12000, $item["quality_points_milli"]);
        self::assertSame(3000, $item["completed_milli"]);
        self::assertTrue($item["history_recorded"]);
        self::assertFalse($item["earlier_history_unknown"]);
        self::assertFalse($item["term_label_retained"]);
        self::assertArrayNotHasKey("actor_id", $item);
        self::assertArrayNotHasKey("reason", $item);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
    }

    public function testAnUngradedRegistrationIsNotPresentedAsCompleted(): void
    {
        [$f, $student] = $this->institution();
        $item = $this->service($f)->page($student, $this->assignment($f, $student))["items"][0];
        self::assertNull($item["grade"]);
        self::assertFalse($item["grade_recorded"]);
        self::assertSame(0, $item["completed_milli"]);
        self::assertTrue($item["history_recorded"]);
    }

    /** Fictional pre-journal facts: no events are invented or removed. */
    private function oldCredit(
        SisFixture $f,
        EntityId $student,
        EntityId $course,
        ?EntityId $term = null,
    ): EntityId {
        $id = EntityId::generate();
        $source = EntityId::generate();
        $f->sql->insert("sis_academic_credits", [
            "id" => $id->value,
            "student_id" => $student->value,
            "course_id" => $course->value,
            "term_id" => ($term ?? $f->term)->value,
            "source_id" => $source->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Observed older work",
            "attempted_milli" => 3000,
            "completed_milli" => 3000,
            "grade_points_milli" => 9000,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        return $id;
    }

    public function testOlderCurrentFactsDoNotInventRetainedGradingOrHistory(): void
    {
        $f = new SisFixture();
        $student = $f->student()->id;
        $course = $f->section()->courseId;
        $credit = $this->oldCredit($f, $student, $course);
        $grade = EntityId::generate();
        $f->legacyGrade(new \Domain\Gradebook\GradeDefinition($grade, "B", 3000, true, true));
        $f->sql->update("sis_academic_credits", ["grade_id" => $grade->value], ["id" => $credit->value]);
        $events = $f->sql->rows("sis_history_events");
        $item = $this->service($f)->page($student, $this->assignment($f, $student))["items"][0];
        self::assertTrue($item["grade_recorded"]);
        self::assertNull($item["grade"]);
        self::assertFalse($item["history_recorded"]);
        self::assertTrue($item["earlier_history_unknown"]);
        self::assertSame(9000, $item["quality_points_milli"]);
        self::assertNull($item["course_version_id"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }

    public function testTransferFactsAndVoidsUseRetainedSourceAndDoNotRestoreTheOldGrade(): void
    {
        $h = new HiatusFixture();
        $t = new TransferAwardFixture($h->sis, $h->cipher);
        $f = $t->sis;
        $assignment = $this->assignment($f, $t->student);
        $service = $this->service($f);
        $item = $service->page($t->student, $assignment)["items"][0];
        self::assertSame("TR", $item["grade"]["code"]);
        self::assertTrue($item["term_label_retained"]);
        self::assertSame("2026-05-01", $item["completed_on"]);
        $change = new AwardChange("void", null, null, "Fictional duplicate transfer award.");
        $plan = $t->service()->preview($t->student, $t->award, $change);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $change, $plan["revision"]);
        $before = $f->sql->rows("sis_history_events");
        $void = $service->page($t->student, $assignment)["items"][0];
        self::assertSame("V", $void["status"]);
        self::assertSame(0, $void["completed_milli"]);
        self::assertNull($void["grade"]);
        self::assertFalse($void["grade_recorded"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testOldTransferHasUnknownGradeHistoryAndItsCurrentSourceMustMatch(): void
    {
        $h = new HiatusFixture();
        $t = new TransferAwardFixture($h->sis, $h->cipher, legacy: true);
        $f = $t->sis;
        $service = $this->service($f);
        $assignment = $this->assignment($f, $t->student);
        $item = $service->page($t->student, $assignment)["items"][0];
        self::assertNull($item["grade"]);
        self::assertTrue($item["earlier_history_unknown"]);
        self::assertFalse($item["history_recorded"]);
        $f->sql->update(
            "sis_transfer_awards",
            ["credit_id" => $this->oldCredit($f, $t->student, $t->course)->value],
            ["id" => $t->award->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $service->page($t->student, $assignment);
    }

    public function testPagingCrossesTermBoundariesAndADeletedAnchorRequiresRestart(): void
    {
        $f = new SisFixture();
        $student = $f->student()->id;
        $course = $f->section()->courseId;
        $term = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => "2027SP",
            "starts_on" => "2027-01-01",
            "ends_on" => "2027-05-01",
            "add_drop_ends_on" => "2027-01-15",
        ]);
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->oldCredit($f, $student, $course, $i < 22 ? $term : $f->term)->value;
        }
        $service = $this->service($f);
        $assignment = $this->assignment($f, $student);
        $first = $service->page($student, $assignment);
        self::assertCount(20, $first["items"]);
        $second = $service->page($student, $assignment, new EntityId($first["next_after"]));
        self::assertCount(5, $second["items"]);
        self::assertNull($second["next_after"]);
        $found = array_column(array_merge($first["items"], $second["items"]), "id");
        self::assertEqualsCanonicalizing($ids, $found);
        self::assertCount(25, array_unique($found));
        $f->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$first["next_after"]]);
        $this->expectException(RecordNotFoundException::class);
        $service->page($student, $assignment, new EntityId($first["next_after"]));
    }

    public function testOwnerAndAcademicReadersHaveAccessButAnUnrelatedUserDoesNot(): void
    {
        [$f, $student] = $this->institution();
        $service = $this->service($f);
        $assignment = $this->assignment($f, $student);
        $f->access->staff = false;
        self::assertCount(1, $service->page($student, $assignment)["items"]);
        $f->access->actor = $f->user();
        $f->access->grants = ["sis:student:read"];
        self::assertCount(1, $service->page($student, $assignment)["items"]);
        $f->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $service->page($student, $assignment);
    }

    public function testAnotherStudentsAssignmentAndCursorAreRejected(): void
    {
        [$f, $student, $section, , $credit] = $this->institution();
        $other = $f->student()->id;
        $assignment = $this->assignment($f, $other);
        $service = $this->service($f);
        try {
            $service->page($student, $assignment);
            self::fail("Another student program was accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $this->oldCredit($f, $other, $section->courseId);
        $this->expectException(RecordNotFoundException::class);
        $service->page($other, $assignment, $credit);
    }

    public function testAProjectionMismatchCannotBecomeAcademicWork(): void
    {
        [$f, $student, , , $credit] = $this->institution();
        $f->sql->update("sis_academic_credits", ["completed_milli" => 3000], ["id" => $credit->value]);
        $this->expectException(CorruptHistoryException::class);
        $this->service($f)->page($student, $this->assignment($f, $student));
    }

    public function testEarlyDropDisappearsWhileTheDefaultLateDropMarkerRemainsVisible(): void
    {
        foreach ([false, true] as $late) {
            [$f, $student, $section] = $this->institution();
            $f->legacyGrade(
                new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "W", 0, false, false),
            );
            if ($late) {
                $f->clock->time = new DateTimeImmutable("2026-08-20T12:00:00Z");
            }
            $review = $f->drops->review($student, $section->id);
            $f->drops->commit(
                $student,
                $section->id,
                new EntityId($review["enrollment_id"]),
                $review["version"],
                new EntityId($review["operation_id"]),
                $review["revision"],
                $review["effective_on"],
            );
            $before = $f->sql->rows("sis_history_events");
            $items = $this->service($f)->page($student, $this->assignment($f, $student))["items"];
            self::assertCount($late ? 1 : 0, $items);
            if ($late) {
                self::assertSame("W", $items[0]["status"]);
                self::assertNull($items[0]["grade"]);
                self::assertFalse($items[0]["grade_recorded"]);
                self::assertSame(0, $items[0]["completed_milli"]);
            }
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testAuthorityIsRecheckedAfterReadingThePage(): void
    {
        [$f, $student] = $this->institution();
        $assignment = $this->assignment($f, $student);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $work = new class ($f) implements AcademicWorkRepository {
            public function __construct(private SisFixture $f)
            {
            }
            public function page(EntityId $student, ?EntityId $after = null): array
            {
                $rows = new SqlAcademicWorkRepository($this->f->sql)->page($student, $after);
                $this->f->access->grants = [];
                return $rows;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $work)->page($student, $assignment);
    }
}
