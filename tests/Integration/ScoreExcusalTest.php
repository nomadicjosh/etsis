<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Assignment;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ScoreExcusalTest extends TestCase
{
    private function fixture(bool $marked = true): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale([
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
        $f->service->register($student->id, $section->id, true);
        $g = $f->gradebook();
        $a = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional quiz",
            1000,
            new DateTimeImmutable("2026-09-01"),
            200,
        );
        $b = new Assignment(EntityId::generate(), $section->id, "Fictional exam", 1000, $a->dueOn);
        $g->addAssignment($a);
        $g->addAssignment($b);
        if ($marked) {
            $g->score($a->id, $student->id, 0, 0);
            $g->score($b->id, $student->id, 800, 0);
        }
        return [$f, $g, $student, $section, $a, $b];
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testExcusalAndInclusionKeepMarksHistoryAndExistingFinalGrades(string $mode): void
    {
        [$f, $g, $student, $section, $a, $b] = $this->fixture();
        $setup = $g->calculationSetup($section->id);
        $g->saveCalculation(
            $section->id,
            $mode,
            $mode === "weighted" ? [$a->id->value => 4000, $b->id->value => 6000] : [],
            $setup["revision"],
            $mode === "category"
                ? [
                    [
                        "title" => "Work",
                        "weight_hundredths" => 10000,
                        "assignments" => [$a->id->value, $b->id->value],
                    ],
                ]
                : [],
        );
        $g->finalGrade($student->id, $section->id, new EntityId($f->sql->rows("sis_grades")[0]["id"]), 0);
        $tables = [
            "sis_history_events",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_enrollments",
            "sis_gradebook_calculations",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $criteria = ["assignment_id" => $a->id->value, "student_id" => $student->id->value];
        $original = $f->sql->one("sis_scores", $criteria);
        $g->score($a->id, $student->id, 0, 1, true);
        $saved = $f->sql->one("sis_scores", $criteria);
        self::assertSame(
            [$original["id"], 0, 1, 2],
            [$saved["id"], $saved["earned_hundredths"], $saved["excused"], $saved["version"]],
        );
        $overview = $g->scoreOverview($student->id, $section->id);
        self::assertSame(
            [8000, 10000, 1, 0],
            [
                $overview["summary"]["percent_hundredths"],
                $overview["summary"]["coverage_hundredths"],
                $overview["summary"]["excused"],
                $overview["summary"]["pending"],
            ],
        );
        self::assertTrue(array_column($overview["items"], "excused", "assignment_id")[$a->id->value]);
        $actor = $f->access->actor;
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        self::assertSame($overview, $g->scoreOverview($student->id, $section->id));
        $f->access->actor = $actor;
        $f->access->staff = true;
        $g->score($a->id, $student->id, 100, 2);
        self::assertTrue(
            array_column($g->scorePage($student->id, $section->id)["items"], "excused", "assignment_id")[
                $a->id->value
            ],
        );
        try {
            $g->score($a->id, $student->id, 100, 2, false);
            self::fail("A stale command included excused work.");
        } catch (ConcurrentModificationException) {
            self::assertSame(8000, $g->summary($student->id, $section->id)["percent_hundredths"]);
        }
        $g->score($a->id, $student->id, 100, 3, false);
        self::assertSame(
            $mode === "weighted" ? 5200 : 4500,
            $g->summary($student->id, $section->id)["percent_hundredths"],
        );
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame($finance, $f->financialEvents());
    }

    public function testUnmarkedExcusalIsDistinctFromPendingAndExplicitInclusionRestoresPending(): void
    {
        [$f, $g, $student, $section, $a] = $this->fixture(false);
        $g->score($a->id, $student->id, null, 0, true);
        $summary = $g->summary($student->id, $section->id);
        self::assertSame([1, 1], [$summary["pending"], $summary["excused"]]);
        self::assertNull($summary["percent_hundredths"]);
        $g->score($a->id, $student->id, null, 1, false);
        self::assertSame(2, $g->summary($student->id, $section->id)["pending"]);
        self::assertSame(0, $g->summary($student->id, $section->id)["excused"]);
        self::assertNull($f->sql->rows("sis_scores")[0]["earned_hundredths"]);
    }

    public function testExcusalCannotHideAnInvalidMarkOrBypassTeachingAuthority(): void
    {
        [$f, $g, $student, $section, $a] = $this->fixture();
        $before = $f->sql->rows("sis_scores");
        try {
            $g->score($a->id, $student->id, 1201, 1, true);
            self::fail("An invalid mark was hidden.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows("sis_scores"));
        }
        $f->access->staff = false;
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        try {
            $g->score($a->id, $student->id, 0, 1, true);
            self::fail("A student excused their work.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_scores"));
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $g->scoreOverview($student->id, $section->id);
    }

    public static function failures(): array
    {
        return [["audit"], ["actor"]];
    }

    #[DataProvider("failures")]
    public function testRequiredAuditOrActorFailureRollsBackMarkFlagAndRevision(string $failure): void
    {
        [$f, , $student, , $a] = $this->fixture();
        $other = $f->user();
        $tables = ["sis_scores", "sis_history_events", "sis_academic_credits"];
        $before = array_map($f->sql->rows(...), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($failure, $f, $other): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional excusal audit failure.");
            }
            $f->access->actor = $other;
        });
        try {
            $f->gradebook($audit)->score($a->id, $student->id, 0, 1, true);
            self::fail("A partial score update committed.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                $failure === "audit" ? \RuntimeException::class : AccessDeniedException::class,
                $error::class,
            );
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }
}
