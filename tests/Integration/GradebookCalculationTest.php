<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\Service\GradebookService;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class GradebookCalculationTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $this->service($f);
        $a = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional quiz",
            1000,
            new DateTimeImmutable("2026-09-01"),
        );
        $b = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional exam",
            10000,
            new DateTimeImmutable("2026-10-01"),
        );
        $service->addAssignment($a);
        $service->addAssignment($b);
        return [$f, $service, $student, $section, $a, $b];
    }

    private function service(SisFixture $f, ?AuditTrail $audit = null): GradebookService
    {
        return new GradebookService(
            new SqlGradebookRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->academicCredits(),
        );
    }

    public function testPointsWeightsUngradedZeroAndExcludedNewAssignments(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        self::assertNull($service->summary($student->id, $section->id)["percent_hundredths"]);
        $service->score($a->id, $student->id, 500, 0);
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(5000, $summary["percent_hundredths"]);
        self::assertSame(909, $summary["coverage_hundredths"]);
        self::assertSame(1, $summary["pending"]);
        $service->score($b->id, $student->id, 0, 0);
        self::assertSame(455, $service->summary($student->id, $section->id)["percent_hundredths"]);
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 4000, $b->id->value => 6000],
            $setup["revision"],
        );
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(2000, $summary["percent_hundredths"]);
        self::assertSame(10000, $summary["coverage_hundredths"]);
        $service->score($b->id, $student->id, null, 1);
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(5000, $summary["percent_hundredths"]);
        self::assertSame(4000, $summary["coverage_hundredths"]);
        $service->addAssignment(
            new Assignment(EntityId::generate(), $section->id, "Later task", 100, $a->dueOn),
        );
        self::assertSame(1, $service->summary($student->id, $section->id)["excluded"]);
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation($section->id, "points", [], $setup["revision"]);
        self::assertSame(2, $service->summary($student->id, $section->id)["pending"]);
    }

    public function testSetupRejectsAssignmentChangesAndConcurrentSaves(): void
    {
        [$f, $service, , $section, $a] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $assignment = $service->assignment($a->id);
        $service->editAssignment($a->id, "Updated quiz", 1000, $a->dueOn, $assignment["revision"]);
        try {
            $service->saveCalculation($section->id, "points", [], $setup["revision"]);
            self::fail("Assignment changes must invalidate setup.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
        }
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation($section->id, "points", [], $setup["revision"]);
        $this->expectException(ConcurrentModificationException::class);
        $service->saveCalculation($section->id, "points", [], $setup["revision"]);
    }

    public function testCrossSectionWeightsAreRejected(): void
    {
        [, $service, , $section] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $this->expectException(ValidationException::class);
        $service->saveCalculation(
            $section->id,
            "weighted",
            [EntityId::generate()->value => 10000],
            $setup["revision"],
        );
    }

    public function testOwnerCanReadFeedbackButCannotChangeSetupOrReadAnotherStudent(): void
    {
        [$f, $service, $student, $section] = $this->fixture();
        $f->access->staff = false;
        $f->access->grants = [];
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        self::assertSame(2, $service->summary($student->id, $section->id)["pending"]);
        try {
            $service->calculationSetup($section->id);
            self::fail("An owner cannot edit faculty setup.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->summary($student->id, $section->id);
    }

    public function testAuditFailureRollsBackWorkingSetup(): void
    {
        [$f, $service, , $section] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional audit failure."));
        try {
            $this->service($f, $audit)->saveCalculation($section->id, "points", [], $setup["revision"]);
            self::fail("A failed audit must roll back setup.");
        } catch (\RuntimeException $error) {
            self::assertSame("Fictional audit failure.", $error->getMessage());
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
        }
    }

    public function testChangingSetupAndScoresLeavesPostedGradeAndHistoryUntouched(): void
    {
        [$f, $service, $student, $section, $a] = $this->fixture();
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "A", 4000, true, true);
        $f->legacyGrade($grade);
        $service->finalGrade($student->id, $section->id, $grade->id, 0);
        $before = [$f->sql->rows("sis_history_events"), $f->sql->rows("sis_academic_credits")];
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation($section->id, "weighted", [$a->id->value => 10000], $setup["revision"]);
        $service->score($a->id, $student->id, 0, 0);
        self::assertSame(0, $service->summary($student->id, $section->id)["percent_hundredths"]);
        self::assertSame($before, [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_academic_credits"),
        ]);
    }

    public function testCalculationNeverReturnsPartialTotalsBeyondItsBound(): void
    {
        $row = [
            "assignment_id" => EntityId::generate()->value,
            "possible_hundredths" => 100,
            "earned_hundredths" => 100,
        ];
        $this->expectException(ValidationException::class);
        new GradebookCalculation()->summarize(array_fill(0, 501, $row));
    }

    public function testWeightedRatiosRespectDifferentPointRangesAndRoundOnlyFeedback(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $summary = new GradebookCalculation("weighted", [$a => 4000, $b => 6000])->summarize([
            ["assignment_id" => $a, "possible_hundredths" => 1000, "earned_hundredths" => 500],
            ["assignment_id" => $b, "possible_hundredths" => 10000, "earned_hundredths" => 8000],
        ]);
        self::assertSame(6800, $summary["percent_hundredths"]);
        self::assertSame(10000, $summary["coverage_hundredths"]);
        self::assertSame(
            6667,
            new GradebookCalculation()->summarize([
                ["assignment_id" => $a, "possible_hundredths" => 300, "earned_hundredths" => 200],
            ])["percent_hundredths"],
        );
    }

    public function testOversizedBookKeepsPagedScoreEditingWithoutReturningPartialAverages(): void
    {
        [$f, $service, $student, $section] = $this->fixture();
        for ($i = 0; $i < 499; ++$i) {
            $f->sql->insert("sis_assignments", [
                "id" => EntityId::generate()->value,
                "section_id" => $section->id->value,
                "title" => "Fictional task " . $i,
                "possible_hundredths" => 100,
                "due_on" => "2026-09-01",
            ]);
        }
        $page = $service->scorePage($student->id, $section->id);
        self::assertCount(50, $page["items"]);
        self::assertNotNull($page["next_after"]);
        try {
            $service->calculationSetup($section->id);
            self::fail("Setup must not edit a truncated list.");
        } catch (ValidationException $error) {
            self::assertStringContainsString("500-assignment", $error->errors[0]["message"]);
        }
        $this->expectException(ValidationException::class);
        $service->summary($student->id, $section->id);
    }

    #[DataProvider("invalidWeights")]
    public function testRejectsInvalidWeights(string $mode, array $weights): void
    {
        $this->expectException(ValidationException::class);
        new GradebookCalculation($mode, $weights);
    }

    public static function invalidWeights(): array
    {
        $id = "01J00000000000000000000001";
        return [
            ["other", []],
            ["points", [$id => 0]],
            ["weighted", []],
            ["weighted", [$id => 9999]],
            ["weighted", [$id => 10001]],
            ["weighted", [$id => "10000"]],
            ["weighted", ["bad" => 10000]],
        ];
    }
}
