<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AttendanceWorksheetTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        return [$f, $f->gradebook(), $student, $section, new DateTimeImmutable("2026-08-11")];
    }

    public function testSetupRequiresADateAndDoesNotInventAttendanceOrEarlierRosterMembership(): void
    {
        [$f, $g, , $section] = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        $setup = $g->attendanceRoster($section->id);
        self::assertNull($setup["held_on"]);
        self::assertSame([], $setup["items"]);
        self::assertArrayHasKey("starts_on", $setup["term"]);
        self::assertSame([], $f->sql->rows("sis_attendance"));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testDateStatusAndNameFiltersKeepUnmarkedDistinctAndOtherSectionsSeparate(): void
    {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $unmarked = $f->worksheetStudent("Unmarked");
        $late = $f->worksheetStudent("L%_!");
        $excused = $f->worksheetStudent("Excused");
        $f->worksheetStudent("Withdrawn", "W");
        $g->attendance($student->id, $section->id, $day, "present");
        $g->attendance($late, $section->id, $day, "late");
        $g->attendance($excused, $section->id, $day, "excused");
        $g->attendance($unmarked, $section->id, $day->modify("+1 day"), "absent");
        $other = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $other->id, true);
        $g->attendance($student->id, $other->id, $day, "absent");
        $tables = [
            "sis_attendance",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_history_events",
            "sis_scores",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $rows = $g->attendanceRoster($section->id, $day)["items"];
        self::assertCount(4, $rows);
        $byStudent = array_column($rows, null, "student_id");
        self::assertSame("present", $byStudent[$student->id->value]["status"]);
        self::assertNull($byStudent[$unmarked->value]["status"]);
        self::assertNull($byStudent[$unmarked->value]["revision"]);
        self::assertSame(
            [$unmarked->value],
            array_column($g->attendanceRoster($section->id, $day, state: "unmarked")["items"], "student_id"),
        );
        self::assertSame(
            [$excused->value],
            array_column($g->attendanceRoster($section->id, $day, state: "excused")["items"], "student_id"),
        );
        self::assertSame(
            [$late->value],
            array_column($g->attendanceRoster($section->id, $day, "L%_! Worksheet")["items"], "student_id"),
        );
        self::assertSame([], $g->attendanceRoster($section->id, $day, state: "absent")["items"]);
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
    }

    public function testRosterPagingHasFiftyRowsAndStableEnrollmentCursors(): void
    {
        [$f, $g, , $section, $day] = $this->fixture();
        for ($index = 0; $index < 51; ++$index) {
            $f->worksheetStudent("Paged " . $index);
        }
        $first = $g->attendanceRoster($section->id, $day);
        $second = $g->attendanceRoster($section->id, $day, after: new EntityId($first["next_after"]));
        self::assertCount(50, $first["items"]);
        self::assertCount(2, $second["items"]);
        self::assertNull($second["next_after"]);
        self::assertCount(
            52,
            array_unique(array_column([...$first["items"], ...$second["items"]], "student_id")),
        );
    }

    public function testCorrectionsReturnFreshVersionsAndAnOldRevisionCannotSurviveAChangedBackMark(): void
    {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $tables = ["sis_scores", "sis_academic_credits", "sis_enrollments", "sis_history_events"];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $first = $g->attendance($student->id, $section->id, $day, "present");
        $second = $g->attendance($student->id, $section->id, $day, "late", $first["revision"]);
        $third = $g->attendance($student->id, $section->id, $day, "present", $second["revision"]);
        self::assertNotSame($first["revision"], $third["revision"]);
        self::assertSame(3, $f->sql->rows("sis_attendance")[0]["version"]);
        try {
            $g->attendance($student->id, $section->id, $day, "absent", $first["revision"]);
            self::fail("A changed-back mark accepted an old correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame("present", $f->sql->rows("sis_attendance")[0]["status"]);
        }
        self::assertSame($third, $g->attendance($student->id, $section->id, $day, "present"));
        self::assertSame(3, $f->sql->rows("sis_attendance")[0]["version"]);
        self::assertSame(
            $third["revision"],
            $g->attendanceRoster($section->id, $day)["items"][0]["revision"],
        );
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        self::assertSame(
            $third["revision"],
            $g->attendanceHistory($student->id, $section->id)["items"][0]["revision"],
        );
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame($finance, $f->financialEvents());
    }

    public function testAChangedUnmarkedRowAndReplacedRegistrationRejectOutdatedInput(): void
    {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $view = $g->attendanceRoster($section->id, $day)["items"][0];
        $g->attendance($student->id, $section->id, $day, "late");
        try {
            $g->attendance($student->id, $section->id, $day, "present", $view["revision"]);
            self::fail("An older unmarked row overwrote a mark.");
        } catch (ConcurrentModificationException) {
            self::assertSame("late", $f->sql->rows("sis_attendance")[0]["status"]);
        }
        $fresh = $g->attendanceRoster($section->id, $day)["items"][0];
        $f->sql->update(
            "sis_enrollments",
            ["id" => EntityId::generate()->value],
            ["id" => $view["enrollment_id"]],
        );
        $this->expectException(ConcurrentModificationException::class);
        $g->attendance(
            $student->id,
            $section->id,
            $day,
            "absent",
            $fresh["revision"],
            new EntityId($view["enrollment_id"]),
        );
    }

    public static function invalidInputs(): array
    {
        return [
            ["2025-01-01", "", "all"],
            ["2028-01-01", "", "all"],
            ["2026-08-11", str_repeat("a", 101), "all"],
            ["2026-08-11", "", "invalid"],
        ];
    }

    #[DataProvider("invalidInputs")]
    public function testInvalidDateOrFiltersCannotLoadARegister(
        string $date,
        string $search,
        string $state,
    ): void {
        [, $g, , $section] = $this->fixture();
        $this->expectException(ValidationException::class);
        $g->attendanceRoster($section->id, new DateTimeImmutable($date), $search, $state);
    }

    public function testTermBoundariesAreInclusiveAndInvalidWritesStayEmpty(): void
    {
        [$f, $g, $student, $section] = $this->fixture();
        $term = $f->registration->term($section->termId);
        foreach ([$term->dates->start, $term->dates->end] as $date) {
            $g->attendanceRoster($section->id, $date);
            $g->attendance($student->id, $section->id, $date, "present");
        }
        $before = $f->sql->rows("sis_attendance");
        try {
            $g->attendance($student->id, $section->id, $term->dates->end->modify("+1 day"), "present");
            self::fail("Attendance outside the term was saved.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows("sis_attendance"));
        }
    }

    public static function failures(): array
    {
        return [["audit", false], ["actor", false], ["audit", true], ["actor", true]];
    }

    #[DataProvider("failures")]
    public function testMarkAndCounterRollBackWhenAuditOrCurrentActorFails(
        string $failure,
        bool $recorded,
    ): void {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $first = $recorded ? $g->attendance($student->id, $section->id, $day, "present") : null;
        $before = $f->sql->rows("sis_attendance");
        $other = $f->user();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f, $failure, $other): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional attendance audit failure.");
            }
            $f->access->actor = $other;
        });
        try {
            $f->gradebook($audit)->attendance(
                $student->id,
                $section->id,
                $day,
                "absent",
                $first["revision"] ?? null,
            );
            self::fail("A partial attendance correction committed.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                $failure === "audit" ? \RuntimeException::class : AccessDeniedException::class,
                $error::class,
            );
            self::assertSame($before, $f->sql->rows("sis_attendance"));
        }
    }

    public function testOwnerCannotReadTheRegisterOrWriteMarksButAssignedFacultyCanReadWithoutDirectoryAccess(): void
    {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $f->access->staff = false;
        $f->access->grants = [];
        self::assertCount(1, $g->attendanceRoster($section->id, $day)["items"]);
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        try {
            $g->attendanceRoster($section->id, $day);
            self::fail("A student read the class register.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_attendance"));
        }
        $this->expectException(AccessDeniedException::class);
        $g->attendance($student->id, $section->id, $day, "present");
    }

    public function testClosedRegistrationCannotReceiveAnotherMark(): void
    {
        [$f, $g, $student, $section, $day] = $this->fixture();
        $f->sql->update("sis_enrollments", ["status" => "W"], ["student_id" => $student->id->value]);
        try {
            $g->attendance($student->id, $section->id, $day, "present");
            self::fail("Attendance was written for a closed registration.");
        } catch (\DomainException) {
            self::assertSame([], $f->sql->rows("sis_attendance"));
        }
    }
}
