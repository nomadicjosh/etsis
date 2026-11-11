<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use DateTimeImmutable;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AcademicWorkService;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatCreditPolicy;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use HiatusFixture;
use Infrastructure\Persistence\Repository\SqlAcademicWorkRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;
use TransferAwardFixture;

final class AcademicPolicyInputsTest extends TestCase
{
    private function grades(int $maximum = 4000): array
    {
        return [
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => $maximum,
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
            [
                "code" => "D",
                "min_score_milli" => 60000,
                "max_score_milli" => 69999,
                "points_milli" => 1000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ];
    }
    private function inputs(SisFixture $f, EntityId $student): array
    {
        $assignment = new EntityId(
            $f->sql->rows("sis_student_programs", ["student_id" => $student->value], 1)[0]["id"],
        );
        $service = new AcademicWorkService(
            new SqlAcademicWorkRepository($f->sql),
            $f->admissions,
            new SqlStudentRecordRepository($f->sql),
            $f->academicCredits(),
            $f->transferCredits(),
            $f->access,
            $f->transactions,
        );
        return array_map(
            PolicyAttempt::fromAcademicWork(...),
            $service->page($student, $assignment)["items"],
        );
    }
    private function policy(SisFixture $f): AuditCalculationPolicy
    {
        return AuditCalculationPolicy::fromRequirements([
            "definition" => [
                "policies" => [
                    "repeat_credit" => "highest_earned",
                    "repeat_gpa" => "all_eligible",
                    "transfer_overall_gpa" => "exclude",
                ],
            ],
            "grade_scale" => $f->gradingScales()->resolve(),
        ]);
    }

    public function testCurrentVerifiedLedgerFactsRemainIndependentOfLaterGradesAndPolicyPublications(): void
    {
        $f = new SisFixture();
        $f->gradeScale($this->grades());
        $policy = $this->policy($f);
        $student = $f->student()->id;
        $section = $f->section(faculty: $f->access->actor);
        $enrollment = $f->service->register($student, $section->id)->id;
        $ledger = $f->academicCredits();
        $choices = $ledger->choices($student, $section->id, $enrollment)["items"];
        $byCode = array_column($choices, "id", "code");
        $review = $ledger->review($student, $section->id, $enrollment, new EntityId($byCode["A"]), 0);
        $ledger->commit($student, $section->id, $review["review_token"]);
        $oldInputs = $this->inputs($f, $student);
        $evaluator = new AcademicPolicyEvaluator();
        $before = $f->sql->rows("sis_history_events");
        $original = $evaluator->evaluate($oldInputs, $policy);
        self::assertSame(4000, $original->gpa?->milli());
        self::assertSame(3000, $original->earnedMilli);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $correction = $ledger->review(
            $student,
            $section->id,
            $enrollment,
            new EntityId($byCode["B"]),
            1,
            reason: "Fictional reviewed assessment correction.",
        );
        $ledger->commit($student, $section->id, $correction["review_token"]);
        $f->gradeScale($this->grades(5000));
        $currentInputs = $this->inputs($f, $student);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(3000, $evaluator->evaluate($currentInputs, $policy)->gpa?->milli());
        self::assertEquals($original, $evaluator->evaluate($oldInputs, $policy));
        $differentPolicy = $evaluator->evaluate($currentInputs, $this->policy($f));
        self::assertNull($differentPolicy->gpa);
        self::assertSame("grading_policy_differs", $differentPolicy->issues[0]["code"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testARecordedLateDropUsesItsGpaFlagAndNeverCreatesEarnedCredit(): void
    {
        $f = new SisFixture();
        $f->gradeScale($this->grades());
        $policy = $this->policy($f);
        $student = $f->student()->id;
        $section = $f->section(20, 0);
        $f->service->register($student, $section->id);
        $f->dropPolicies->save($f->term, -1, new DropWindows([["through" => null, "grade_code" => "D"]]));
        $f->clock->time = new DateTimeImmutable("2026-08-20T12:00:00Z");
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
        $result = new AcademicPolicyEvaluator()->evaluate($this->inputs($f, $student), $policy);
        self::assertSame(0, $result->earnedMilli);
        self::assertSame(1000, $result->gpa?->milli());
        self::assertSame(3000, $result->gpa?->creditsMilli);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testAcceptedTransferFactsAndLaterVoidUseTheSameReadBoundary(): void
    {
        $h = new HiatusFixture();
        $t = new TransferAwardFixture($h->sis, $h->cipher);
        $f = $t->sis;
        $policy = new AuditCalculationPolicy(
            RepeatCreditPolicy::HighestEarned,
            RepeatGpaPolicy::AllEligible,
            TransferGpaPolicy::IncludeEligible,
            null,
            null,
        );
        $evaluator = new AcademicPolicyEvaluator();
        $before = $f->sql->rows("sis_history_events");
        $result = $evaluator->evaluate($this->inputs($f, $t->student), $policy);
        self::assertSame(3000, $result->earnedMilli);
        self::assertSame(0, $result->residentMinimumMilli);
        self::assertNull($result->gpa?->milli());
        self::assertSame([], $result->issues);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $change = new AwardChange("void", null, null, "Fictional duplicate transfer decision.");
        $plan = $t->service()->preview($t->student, $t->award, $change);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $change, $plan["revision"]);
        $result = $evaluator->evaluate($this->inputs($f, $t->student), $policy);
        self::assertSame(0, $result->earnedMilli);
        self::assertSame([], $result->issues);
    }
}
