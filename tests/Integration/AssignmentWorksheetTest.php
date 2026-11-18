<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Gradebook\Assignment;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AssignmentWorksheetTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $g = $f->gradebook();
        $assignment = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional quiz",
            1000,
            new DateTimeImmutable("2026-09-01"),
            200,
        );
        $g->addAssignment($assignment);
        return [$f, $g, $student, $section, $assignment];
    }

    public function testPagedWorksheetIncludesPendingZeroAndExcusedMarksWithoutChangingAnyFacts(): void
    {
        [$f, $g, $student, , $a] = $this->fixture();
        $zero = $f->worksheetStudent("Zero");
        $excused = $f->worksheetStudent("Excused");
        $pending = $f->worksheetStudent("NoMark");
        $f->worksheetStudent("Closed", "W");
        $g->score($a->id, $student->id, 1150, 0);
        $g->score($a->id, $zero, 0, 0);
        $g->score($a->id, $excused, 400, 0, true);
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_scores",
            "sis_enrollments",
            "sis_academic_credits",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $page = $g->scoreRoster($a->id);
        self::assertCount(4, $page["items"]);
        self::assertNull($page["next_after"]);
        self::assertSame($g->assignment($a->id), $page["assignment"]);
        $byStudent = array_column($page["items"], null, "student_id");
        self::assertSame(
            [1150, 0, 400, null],
            array_map(static fn(EntityId $id): ?int => $byStudent[$id->value]["earned_hundredths"], [
                $student->id,
                $zero,
                $excused,
                $pending,
            ]),
        );
        self::assertSame(
            [false, false, true, false],
            array_map(static fn(EntityId $id): bool => $byStudent[$id->value]["excused"], [
                $student->id,
                $zero,
                $excused,
                $pending,
            ]),
        );
        self::assertSame(0, $byStudent[$pending->value]["version"]);
        self::assertSame(
            [$pending->value],
            array_column($g->scoreRoster($a->id, state: "pending")["items"], "student_id"),
        );
        self::assertCount(2, $g->scoreRoster($a->id, state: "scored")["items"]);
        self::assertSame(
            [$excused->value],
            array_column($g->scoreRoster($a->id, state: "excused")["items"], "student_id"),
        );
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame($finance, $f->financialEvents());
    }

    public function testSearchTreatsWildcardsLiterallyAndSupportsNameTokensAndCurrentId(): void
    {
        [$f, $g, , , $a] = $this->fixture();
        $literal = $f->worksheetStudent("Ab%_!");
        $f->worksheetStudent("AbOther");
        $match = $g->scoreRoster($a->id, "ab%_! worksheet")["items"];
        self::assertSame([$literal->value], array_column($match, "student_id"));
        self::assertSame($match, $g->scoreRoster($a->id, $match[0]["institutional_id"])["items"]);
        self::assertSame([], $g->scoreRoster($a->id, "' OR 1=1 --")["items"]);
        $person = $f->sql->one("sis_students", ["id" => $literal->value])["person_id"];
        $f->sql->update(
            "sis_person_identifiers",
            ["institutional_id" => "CURRENT.42"],
            ["person_id" => $person],
        );
        self::assertSame(
            [$literal->value],
            array_column($g->scoreRoster($a->id, "CURRENT.42")["items"], "student_id"),
        );
    }

    public function testCursorReturnsAtMostFiftyActiveStudentsWithNoDuplicatesOrCrossSectionScores(): void
    {
        [$f, $g, $student, , $a] = $this->fixture();
        for ($index = 0; $index < 51; ++$index) {
            $f->worksheetStudent("Paged " . $index);
        }
        $other = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $other->id, true);
        $different = new Assignment(EntityId::generate(), $other->id, "Other quiz", 1000, $a->dueOn);
        $g->addAssignment($different);
        $g->score($different->id, $student->id, 999, 0);
        $first = $g->scoreRoster($a->id);
        $second = $g->scoreRoster($a->id, after: new EntityId($first["next_after"]));
        self::assertCount(50, $first["items"]);
        self::assertCount(2, $second["items"]);
        self::assertNull($second["next_after"]);
        $all = [...$first["items"], ...$second["items"]];
        self::assertCount(52, array_unique(array_column($all, "student_id")));
        self::assertSame(array_fill(0, 52, null), array_column($all, "earned_hundredths"));
    }

    public static function assignmentChanges(): array
    {
        return [["title"], ["points"], ["bonus"], ["due"]];
    }

    #[DataProvider("assignmentChanges")]
    public function testWorksheetScoreCannotUseAChangedAssignmentDefinition(string $change): void
    {
        [$f, $g, $student, , $a] = $this->fixture();
        $view = $g->scoreRoster($a->id);
        $g->editAssignment(
            $a->id,
            $change === "title" ? "Changed title" : $a->title,
            $change === "points" ? 1500 : 1000,
            $change === "due" ? new DateTimeImmutable("2026-09-02") : $a->dueOn,
            $view["assignment"]["revision"],
            $change === "bonus" ? 300 : 200,
        );
        try {
            $g->score($a->id, $student->id, 600, 0, false, $view["assignment"]["revision"]);
            self::fail("An outdated assignment worksheet saved a mark.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_scores"));
        }
        $fresh = $g->scoreRoster($a->id);
        $g->score($a->id, $student->id, 0, 0, true, $fresh["assignment"]["revision"]);
        $saved = $g->scoreRoster($a->id)["items"][0];
        self::assertSame([0, true, 1], [$saved["earned_hundredths"], $saved["excused"], $saved["version"]]);
        $this->expectException(ConcurrentModificationException::class);
        $g->score($a->id, $student->id, null, 0, false, $fresh["assignment"]["revision"]);
    }

    public function testStudentOwnersAndUnassignedFacultyCannotReadTheRoster(): void
    {
        [$f, $g, $student, , $a] = $this->fixture();
        foreach ([$f->admissions->person($student->personId)->ownerUserId, $f->user()] as $actor) {
            $f->access->staff = false;
            $f->access->actor = $actor;
            try {
                $g->scoreRoster($a->id);
                self::fail("Private class scores were exposed.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAssignedFacultyNeedsNoGeneralStudentDirectoryPermission(): void
    {
        [$f, $g, , , $a] = $this->fixture();
        $f->access->staff = false;
        $f->access->grants = [];
        self::assertCount(1, $g->scoreRoster($a->id)["items"]);
    }

    public function testAReplacedRegistrationCannotReceiveAnOlderWorksheetMark(): void
    {
        [$f, $g, $student, , $a] = $this->fixture();
        $view = $g->scoreRoster($a->id);
        $enrollment = $view["items"][0]["enrollment_id"];
        $f->sql->update("sis_enrollments", ["id" => EntityId::generate()->value], ["id" => $enrollment]);
        try {
            $g->score(
                $a->id,
                $student->id,
                700,
                0,
                false,
                $view["assignment"]["revision"],
                new EntityId($enrollment),
            );
            self::fail("An old worksheet was applied to a different registration.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_scores"));
        }
    }

    public static function invalidFilters(): array
    {
        return [[str_repeat("a", 101), "all"], ["bad\nsearch", "all"], ["", "unknown"]];
    }

    #[DataProvider("invalidFilters")]
    public function testUnboundedOrInvalidFiltersAreRejected(string $search, string $state): void
    {
        [, $g, , , $a] = $this->fixture();
        $this->expectException(ValidationException::class);
        $g->scoreRoster($a->id, $search, $state);
    }
}
