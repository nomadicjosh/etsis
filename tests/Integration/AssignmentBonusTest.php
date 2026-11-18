<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AssignmentBonusTest extends TestCase
{
    private function service(SisFixture $f, ?AuditTrail $audit = null): GradebookService
    {
        return $f->gradebook($audit);
    }

    private function fixture(int $bonus = 2000): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $this->service($f);
        $assignment = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional bonus assignment",
            10000,
            new DateTimeImmutable("2026-09-01"),
            $bonus,
        );
        $service->addAssignment($assignment);
        return [$f, $service, $student, $section, $assignment];
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testBonusFeedbackIsSharedWithoutChangingPostedAcademicFacts(string $mode): void
    {
        [$f, $service, $student, $section, $a] = $this->fixture();
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
        $review = $service->reviewFinalGrade(
            $student->id,
            $section->id,
            new EntityId($f->sql->rows("sis_grades")[0]["id"]),
            0,
        );
        $service->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $tables = ["sis_history_events", "sis_history_operations", "sis_academic_credits", "sis_enrollments"];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            $mode,
            $mode === "weighted" ? [$a->id->value => 10000] : [],
            $setup["revision"],
            $mode === "category"
                ? [["title" => "Work", "weight_hundredths" => 10000, "assignments" => [$a->id->value]]]
                : [],
        );
        $service->score($a->id, $student->id, 11500, 0);
        $overview = $service->scoreOverview($student->id, $section->id);
        self::assertSame(11500, $overview["summary"]["percent_hundredths"]);
        self::assertSame(1500, $overview["summary"]["bonus_earned_hundredths"]);
        self::assertSame(
            12000,
            $service->scorePage($student->id, $section->id)["items"][0]["score_limit_hundredths"],
        );
        self::assertSame(2000, $service->assignment($a->id)["bonus_hundredths"]);
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $f->access->grants = [];
        self::assertSame($overview, $service->scoreOverview($student->id, $section->id));
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame($finance, $f->financialEvents());
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->scoreOverview($student->id, $section->id);
    }

    public function testBonusAndBaseLimitsProtectExistingScoresAndAssignmentRevisions(): void
    {
        [$f, $service, $student, , $a] = $this->fixture();
        $service->score($a->id, $student->id, 11500, 0);
        $record = $service->assignment($a->id);
        try {
            $service->editAssignment($a->id, $a->title, 10000, $a->dueOn, $record["revision"], 1000);
            self::fail("The allowed score fell below an existing mark.");
        } catch (ValidationException) {
            self::assertSame(2000, $service->assignment($a->id)["bonus_hundredths"]);
        }
        $service->editAssignment($a->id, $a->title, 10000, $a->dueOn, $record["revision"], 2500);
        try {
            $service->editAssignment($a->id, $a->title, 10000, $a->dueOn, $record["revision"], 3000);
            self::fail("A stale assignment revision changed its bonus.");
        } catch (ConcurrentModificationException) {
            self::assertSame(2500, $service->assignment($a->id)["bonus_hundredths"]);
        }
        try {
            $service->score($a->id, $student->id, 12501, 1);
            self::fail("The bonus score exceeded its explicit limit.");
        } catch (ValidationException) {
            self::assertSame(11500, $f->sql->rows("sis_scores")[0]["earned_hundredths"]);
        }
        $service->score($a->id, $student->id, null, 1);
        $current = $service->assignment($a->id);
        $service->editAssignment($a->id, $a->title, 10000, $a->dueOn, $current["revision"], 0);
        self::assertSame(0, $service->assignment($a->id)["bonus_hundredths"]);
        $this->expectException(ValidationException::class);
        $service->score($a->id, $student->id, 10001, 2);
    }

    public static function failures(): array
    {
        return [
            ["add", "audit"],
            ["add", "actor"],
            ["edit", "audit"],
            ["edit", "actor"],
            ["score", "audit"],
            ["score", "actor"],
        ];
    }

    #[DataProvider("failures")]
    public function testAssignmentAndScoreMutationsRollBackAfterAuditOrAuthorityFailure(
        string $action,
        string $failure,
    ): void {
        [$f, $service, $student, $section, $a] = $this->fixture();
        $other = $f->user();
        $record = $service->assignment($a->id);
        $tables = ["sis_assignments", "sis_scores", "sis_history_events", "sis_academic_credits"];
        $before = array_map($f->sql->rows(...), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($failure, $f, $other): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional bonus audit failure.");
            }
            $f->access->actor = $other;
        });
        $service = $this->service($f, $audit);
        try {
            if ($action === "add") {
                $service->addAssignment(
                    new Assignment(
                        EntityId::generate(),
                        $section->id,
                        "Fictional added work",
                        1000,
                        $a->dueOn,
                        100,
                    ),
                );
            } elseif ($action === "edit") {
                $service->editAssignment($a->id, $a->title, 10000, $a->dueOn, $record["revision"], 3000);
            } else {
                $service->score($a->id, $student->id, 11500, 0);
            }
            self::fail("The mutation survived a required completion-time check.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                $failure === "audit" ? \RuntimeException::class : AccessDeniedException::class,
                $error::class,
            );
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }
}
