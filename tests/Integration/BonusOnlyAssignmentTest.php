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

final class BonusOnlyAssignmentTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $f->gradebook();
        $a = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional exam",
            10000,
            new DateTimeImmutable("2026-09-01"),
        );
        $b = new Assignment(EntityId::generate(), $section->id, "Optional reflection", 0, $a->dueOn, 1000);
        $service->addAssignment($a);
        $service->addAssignment($b);
        return [$f, $service, $student, $section, $a, $b];
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testSetupScoringAndPrivateFeedbackShareBonusRulesAndPreserveOfficialFacts(
        string $mode,
    ): void {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
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
        $grade = new EntityId($f->sql->rows("sis_grades")[0]["id"]);
        $review = $service->reviewFinalGrade($student->id, $section->id, $grade, 0);
        $service->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $tables = ["sis_history_events", "sis_history_operations", "sis_academic_credits", "sis_enrollments"];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            $mode,
            $mode === "weighted" ? [$a->id->value => 10000, $b->id->value => 1000] : [],
            $setup["revision"],
            $mode === "category"
                ? [
                    [
                        "title" => "Work",
                        "weight_hundredths" => 10000,
                        "assignments" => [$a->id->value, $b->id->value],
                        "drop_lowest" => 1,
                    ],
                ]
                : [],
        );
        $service->score($a->id, $student->id, 9200, 0);
        self::assertSame(1, $service->summary($student->id, $section->id)["bonus_pending"]);
        self::assertSame(0, $service->summary($student->id, $section->id)["pending"]);
        $service->score($b->id, $student->id, 500, 0);
        $overview = $service->scoreOverview($student->id, $section->id);
        self::assertSame(9700, $overview["summary"]["percent_hundredths"]);
        self::assertSame(10000, $overview["summary"]["coverage_hundredths"]);
        $page = $service->scorePage($student->id, $section->id);
        $bonus = array_column($page["items"], null, "assignment_id")[$b->id->value];
        self::assertTrue($bonus["bonus_only"]);
        self::assertSame(1000, $bonus["score_limit_hundredths"]);
        self::assertSame(0, $service->scoreRoster($b->id)["assignment"]["possible_hundredths"]);
        self::assertSame(500, $service->scoreRoster($b->id)["items"][0]["earned_hundredths"]);
        $service->score($b->id, $student->id, 500, 1, true);
        self::assertSame(9200, $service->summary($student->id, $section->id)["percent_hundredths"]);
        $service->score($b->id, $student->id, 500, 2, false);
        $overview = $service->scoreOverview($student->id, $section->id);
        $page = $service->scorePage($student->id, $section->id);
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $f->access->grants = [];
        self::assertSame($overview, $service->scoreOverview($student->id, $section->id));
        self::assertSame($page, $service->scorePage($student->id, $section->id));
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertSame($finance, $f->financialEvents());
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->scoreOverview($student->id, $section->id);
    }

    public function testWeightedTypeChangesRequireExplicitRebalancingAndProtectMarks(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 10000, $b->id->value => 1000],
            $setup["revision"],
        );
        $record = $service->assignment($b->id);
        try {
            $service->editAssignment($b->id, $b->title, 1000, $b->dueOn, $record["revision"], 0);
            self::fail("A bonus weight silently became part of the required 100%.");
        } catch (ValidationException) {
            self::assertSame(0, $service->assignment($b->id)["possible_hundredths"]);
        }
        $service->score($b->id, $student->id, 500, 0);
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 10000, $b->id->value => 0],
            $setup["revision"],
        );
        $service->editAssignment($b->id, $b->title, 1000, $b->dueOn, $record["revision"], 0);
        self::assertSame(500, $f->sql->rows("sis_scores")[0]["earned_hundredths"]);
        $record = $service->assignment($b->id);
        $service->editAssignment($b->id, $b->title, 0, $b->dueOn, $record["revision"], 1000);
        $this->expectException(ConcurrentModificationException::class);
        $service->editAssignment($b->id, $b->title, 0, $b->dueOn, $record["revision"], 2000);
    }

    public function testBonusLimitsAndStaleSetupCannotChangeStoredWork(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $service->score($b->id, $student->id, 1000, 0);
        $record = $service->assignment($b->id);
        try {
            $service->score($b->id, $student->id, 1001, 1);
            self::fail("A bonus-only mark exceeded its limit.");
        } catch (ValidationException) {
            self::assertSame(1000, $f->sql->rows("sis_scores")[0]["earned_hundredths"]);
        }
        try {
            $service->editAssignment($b->id, $b->title, 0, $b->dueOn, $record["revision"], 999);
            self::fail("A bonus limit fell below its saved mark.");
        } catch (ValidationException) {
            self::assertSame(1000, $service->assignment($b->id)["bonus_hundredths"]);
        }
        $setup = $service->calculationSetup($section->id);
        $service->editAssignment($b->id, $b->title, 0, $b->dueOn, $record["revision"], 2000);
        $this->expectException(ConcurrentModificationException::class);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 10000, $b->id->value => 1000],
            $setup["revision"],
        );
    }

    public function testOversizedBooksKeepBoundedScorePagesWithConfiguredBonusWeights(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 10000, $b->id->value => 1000],
            $setup["revision"],
        );
        for ($i = 0; $i < 499; ++$i) {
            $f->sql->insert("sis_assignments", [
                "id" => EntityId::generate()->value,
                "section_id" => $section->id->value,
                "title" => "Fictional later task",
                "possible_hundredths" => 100,
                "due_on" => "2026-09-01",
            ]);
        }
        $page = $service->scorePage($student->id, $section->id);
        self::assertCount(50, $page["items"]);
        self::assertNotNull($page["next_after"]);
        $this->expectException(ValidationException::class);
        $service->summary($student->id, $section->id);
    }

    public function testConfiguredBonusLookupNeverCrossesTheSectionBoundary(): void
    {
        [$f, , , $section, , $b] = $this->fixture();
        $repository = new \Infrastructure\Persistence\Repository\SqlGradebookRepository($f->sql);
        self::assertSame([$b->id->value], $repository->bonusAssignmentIds($section->id, [$b->id->value]));
        self::assertSame([], $repository->bonusAssignmentIds($f->section()->id, [$b->id->value]));
        self::assertSame([], $repository->bonusAssignmentIds($section->id, []));
    }
}
