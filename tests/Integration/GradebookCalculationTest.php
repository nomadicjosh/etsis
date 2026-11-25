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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class GradebookCalculationTest extends TestCase
{
    public function testCategoryRulesPersistAndGiveTheOwnerTheSameFeedbackWithoutChangingRecordedFacts(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $bonus = new Assignment(
            EntityId::generate(),
            $section->id,
            'Fictional optional bonus',
            0,
            $a->dueOn,
            1000
        );
        $service->addAssignment($bonus);
        $service->score($a->id, $student->id, 500, 0);
        $service->score($b->id, $student->id, 9000, 0);
        $service->score($bonus->id, $student->id, 1000, 0);
        $tables = ['sis_scores', 'sis_academic_credits', 'sis_enrollments', 'sis_history_events',
            'sis_history_operations'];
        $before = array_map($f->sql->rows(...), $tables);
        $categories = [['title' => 'Fictional course work', 'weight_hundredths' => 10000,
            'assignments' => [$a->id->value, $b->id->value, $bonus->id->value], 'drop_lowest' => 1,
            'protected_assignments' => [$a->id->value], 'cap_at_hundred' => true]];
        $setup = $service->calculationSetup($section->id);
        $saved = $service->saveCalculation($section->id, 'category', [], $setup['revision'], $categories);
        self::assertSame($categories, $saved['categories']);
        $overview = $service->scoreOverview($student->id, $section->id);
        self::assertSame(10000, $overview['summary']['percent_hundredths']);
        self::assertSame(15000, $overview['summary']['categories'][0]['raw_percent_hundredths']);
        self::assertSame([$b->id->value], array_column($overview['summary']['dropped_assignments'], 'assignment_id'));
        $items = array_column($overview['items'], null, 'assignment_id');
        self::assertTrue($items[$a->id->value]['drop_protected']);
        self::assertFalse($items[$bonus->id->value]['drop_protected']);
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $f->access->staff = false;
        $f->access->grants = [];
        self::assertSame($overview, $service->scoreOverview($student->id, $section->id));
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->scoreOverview($student->id, $section->id);
    }

    public function testProtectedAssignmentMustBeUnprotectedBeforeConversionToBonusOnly(): void
    {
        [$f, $service, , $section, $a] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $categories = [['title' => 'Fictional exams', 'weight_hundredths' => 10000,
            'assignments' => [$a->id->value], 'protected_assignments' => [$a->id->value]]];
        $service->saveCalculation($section->id, 'category', [], $setup['revision'], $categories);
        $assignment = $service->assignment($a->id);
        $before = $f->sql->rows('sis_assignments');
        try {
            $service->editAssignment($a->id, $a->title, 0, $a->dueOn, $assignment['revision'], 1000);
            self::fail('Protected regular work became bonus-only work.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('automatic-drop protection', $e->errors[0]['message']);
        }
        self::assertSame($before, $f->sql->rows('sis_assignments'));
        unset($categories[0]['protected_assignments']);
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation($section->id, 'category', [], $setup['revision'], $categories);
        $service->editAssignment($a->id, $a->title, 0, $a->dueOn, $assignment['revision'], 1000);
        self::assertSame(0, $service->assignment($a->id)['possible_hundredths']);
    }

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
        return $f->gradebook($audit);
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

    public function testDroppedScoreFeedbackIsSharedWithTheOwnerAndPreservesScoresFinalGradesAndHistory(): void
    {
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
        $service->score($a->id, $student->id, 500, 0);
        $service->score($b->id, $student->id, 9000, 0);
        $tables = [
            "sis_scores",
            "sis_academic_credits",
            "sis_enrollments",
            "sis_history_events",
            "sis_history_operations",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $finance = $f->financialEvents();
        $setup = $service->calculationSetup($section->id);
        $categories = [
            [
                "title" => "Coursework",
                "weight_hundredths" => 10000,
                "assignments" => [$a->id->value, $b->id->value],
                "drop_lowest" => 1,
            ],
        ];
        $saved = $service->saveCalculation($section->id, "category", [], $setup["revision"], $categories);
        self::assertSame($categories, $saved["categories"]);
        $overview = $service->scoreOverview($student->id, $section->id);
        self::assertSame(9000, $overview["summary"]["percent_hundredths"]);
        self::assertSame(
            [$a->id->value],
            array_column($overview["summary"]["dropped_assignments"], "assignment_id"),
        );
        $items = array_column($overview["items"], null, "assignment_id");
        self::assertTrue($items[$a->id->value]["dropped"]);
        self::assertFalse($items[$a->id->value]["excluded"]);
        self::assertFalse($items[$b->id->value]["dropped"]);
        self::assertSame(1, $items[$a->id->value]["category_drop_lowest"]);
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

    public function testDropRuleSaveRollsBackWhenTheStaffAccountChangesDuringAudit(): void
    {
        [$f, , , $section, $a, $b] = $this->fixture();
        $other = $f->user();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f, $other): void {
            $f->access->actor = $other;
        });
        $service = $this->service($f, $audit);
        $setup = $service->calculationSetup($section->id);
        $before = $f->sql->rows("sis_history_events");
        try {
            $service->saveCalculation($section->id, "category", [], $setup["revision"], [
                [
                    "title" => "Coursework",
                    "weight_hundredths" => 10000,
                    "assignments" => [$a->id->value, $b->id->value],
                    "drop_lowest" => 1,
                ],
            ]);
            self::fail("A changed staff account committed grading settings.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testDroppedSelectionChangesWithScoresWithoutChangingTheSavedRule(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $service->score($a->id, $student->id, 500, 0);
        $service->score($b->id, $student->id, 9000, 0);
        $setup = $service->calculationSetup($section->id);
        $service->saveCalculation($section->id, "category", [], $setup["revision"], [
            [
                "title" => "Coursework",
                "weight_hundredths" => 10000,
                "assignments" => [$a->id->value, $b->id->value],
                "drop_lowest" => 1,
            ],
        ]);
        $stored = $f->sql->rows("sis_gradebook_calculations");
        $events = $f->sql->rows("sis_history_events");
        $service->score($a->id, $student->id, 1000, 1);
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(10000, $summary["percent_hundredths"]);
        self::assertSame([$b->id->value], array_column($summary["dropped_assignments"], "assignment_id"));
        $service->score($a->id, $student->id, null, 2);
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(9000, $summary["percent_hundredths"]);
        self::assertSame(1, $summary["pending"]);
        self::assertSame(0, $summary["dropped"]);
        self::assertSame($stored, $f->sql->rows("sis_gradebook_calculations"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
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
        [$f, $service, , $section, $a] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional audit failure."));
        try {
            $this->service($f, $audit)->saveCalculation($section->id, "category", [], $setup["revision"], [
                ["title" => "Tasks", "weight_hundredths" => 10000, "assignments" => [$a->id->value]],
            ]);
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
        $saved = $service->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 10000],
            $setup["revision"],
        );
        $service->saveCalculation($section->id, "category", [], $saved["revision"], [
            ["title" => "Tasks", "weight_hundredths" => 10000, "assignments" => [$a->id->value]],
        ]);
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

    public function testCategorySetupCalculationPresentationAndReturningToPoints(): void
    {
        [$f, $service, $student, $section, $a, $b] = $this->fixture();
        $service->score($a->id, $student->id, 500, 0);
        $service->score($b->id, $student->id, 8000, 0);
        $before = [$f->sql->rows("sis_history_events"), $f->sql->rows("sis_academic_credits")];
        $groups = [
            ["title" => "Quizzes", "weight_hundredths" => 4000, "assignments" => [$a->id->value]],
            ["title" => "Exams", "weight_hundredths" => 6000, "assignments" => [$b->id->value]],
        ];
        $setup = $service->calculationSetup($section->id);
        $saved = $service->saveCalculation($section->id, "category", [], $setup["revision"], $groups);
        self::assertSame($groups, $saved["categories"]);
        $overview = $service->scoreOverview($student->id, $section->id);
        self::assertSame(6800, $overview["summary"]["percent_hundredths"]);
        self::assertSame(10000, $overview["summary"]["coverage_hundredths"]);
        self::assertSame("Quizzes", $overview["items"][0]["category_title"]);
        self::assertNull($overview["items"][0]["weight_hundredths"]);
        self::assertFalse($overview["items"][0]["excluded"]);
        self::assertSame(4000, $overview["items"][0]["category_weight_hundredths"]);
        self::assertSame(
            "Quizzes",
            $service->scorePage($student->id, $section->id)["items"][0]["category_title"],
        );
        $service->score($b->id, $student->id, null, 1);
        $summary = $service->summary($student->id, $section->id);
        self::assertSame(5000, $summary["percent_hundredths"]);
        self::assertSame(4000, $summary["coverage_hundredths"]);
        self::assertSame(1, $summary["awaiting_categories"]);
        $service->saveCalculation($section->id, "points", [], $saved["revision"]);
        self::assertSame([], $service->calculationSetup($section->id)["categories"]);
        self::assertSame($before, [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_academic_credits"),
        ]);
    }

    public function testPartlyGradedCategoriesUsePointsAndDoNotClaimCompleteCoverage(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $c = EntityId::generate()->value;
        $d = EntityId::generate()->value;
        $calculator = new GradebookCalculation(
            "category",
            [],
            [
                ["title" => "Tasks", "weight_hundredths" => 4000, "assignments" => [$a, $b]],
                ["title" => "Exam", "weight_hundredths" => 5000, "assignments" => [$c]],
                ["title" => "Final", "weight_hundredths" => 1000, "assignments" => []],
                ["title" => "Practice", "weight_hundredths" => 0, "assignments" => [$d]],
            ],
        );
        $scores = [
            ["assignment_id" => $a, "possible_hundredths" => 1000, "earned_hundredths" => 500],
            ["assignment_id" => $b, "possible_hundredths" => 9000, "earned_hundredths" => null],
            ["assignment_id" => $c, "possible_hundredths" => 10000, "earned_hundredths" => 8000],
            ["assignment_id" => $d, "possible_hundredths" => 10000, "earned_hundredths" => 0],
        ];
        $summary = $calculator->summarize($scores);
        self::assertSame(6667, $summary["percent_hundredths"]);
        self::assertSame(5400, $summary["coverage_hundredths"]);
        self::assertSame(1, $summary["pending"]);
        self::assertSame(1, $summary["excluded"]);
        self::assertSame(1, $summary["awaiting_categories"]);
        self::assertSame(1000, $summary["categories"][0]["coverage_hundredths"]);
        self::assertNull($summary["categories"][2]["percent_hundredths"]);
        $scores[1]["earned_hundredths"] = 0;
        $summary = $calculator->summarize($scores);
        self::assertSame(4667, $summary["percent_hundredths"]);
        self::assertSame(9000, $summary["coverage_hundredths"]);
        self::assertSame(500, $summary["categories"][0]["percent_hundredths"]);
    }

    public function testCategoryMembershipMustStayInTheSelectedSectionAndRejectStaleEdits(): void
    {
        [$f, $service, , $section, $a] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        try {
            $service->saveCalculation($section->id, "category", [], $setup["revision"], [
                [
                    "title" => "Wrong section",
                    "weight_hundredths" => 10000,
                    "assignments" => [EntityId::generate()->value],
                ],
            ]);
            self::fail("Cross-section category membership was accepted.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
        }
        $groups = [["title" => "Coursework", "weight_hundredths" => 10000, "assignments" => [$a->id->value]]];
        $saved = $service->saveCalculation($section->id, "category", [], $setup["revision"], $groups);
        self::assertNotSame($setup["revision"], $saved["revision"]);
        $this->expectException(ConcurrentModificationException::class);
        $service->saveCalculation($section->id, "category", [], $setup["revision"], $groups);
    }

    #[DataProvider("invalidCategories")]
    public function testCategoryStructureAndOwnershipAreStrict(array $categories): void
    {
        $this->expectException(ValidationException::class);
        new GradebookCalculation("category", [], $categories);
    }

    public static function invalidCategories(): array
    {
        $a = "01J00000000000000000000001";
        $group = ["title" => "Tasks", "weight_hundredths" => 10000, "assignments" => [$a]];
        return [
            [[]],
            [["named" => $group]],
            [[array_replace($group, ["title" => ""])]],
            [[array_replace($group, ["title" => "Line\nBreak"])]],
            [[array_replace($group, ["weight_hundredths" => 9999])]],
            [[array_replace($group, ["weight_hundredths" => "10000"])]],
            [[array_replace($group, ["assignments" => [$a, $a]])]],
            [[array_replace($group, ["assignments" => ["bad"]])]],
            [[array_replace($group, ["assignments" => ["key" => $a]])]],
            [[$group, ["title" => "tasks", "weight_hundredths" => 0, "assignments" => []]]],
            [[$group, ["title" => "Other", "weight_hundredths" => 0, "assignments" => [$a]]]],
            [[array_replace($group, ["extra" => true])]],
            [array_fill(0, 21, $group)],
        ];
    }
    public function testPolicyDutyIsIndependentAndWeightsOnlySavesPreserveTheSavedPolicy(): void
    {
        [$f, $service, , $section] = $this->fixture();
        $setup = $service->calculationSetup($section->id);
        $policy = new \Domain\Gradebook\ValueObject\CourseworkGradePolicy([
            "rounding" => "half_even",
            "decimal_places" => 1,
            "cap_at_hundred" => true,
        ]);
        $f->access->staff = false;
        self::assertFalse($service->calculationSetup($section->id)["can_manage_grade_policy"]);
        try {
            $service->saveCalculation(
                $section->id,
                "points",
                [],
                $setup["revision"],
                gradePolicy: $policy,
            );
            self::fail("Assigned faculty must not gain policy authority implicitly.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
        }
        $f->access->grants = ["sis:gradebook:calculation-policy"];
        $saved = $service->saveCalculation(
            $section->id,
            "points",
            [],
            $setup["revision"],
            gradePolicy: $policy,
        );
        self::assertTrue($saved["can_manage_grade_policy"]);
        $f->access->grants = [];
        $saved = $service->saveCalculation($section->id, "points", [], $saved["revision"]);
        self::assertSame($policy->facts, $saved["grade_policy"]);
        self::assertFalse($saved["can_manage_grade_policy"]);
        $f->access->actor = $f->user();
        $f->access->grants = ["sis:gradebook:calculation-policy"];
        $this->expectException(AccessDeniedException::class);
        $service->calculationSetup($section->id);
    }

    public static function policyAuditChanges(): array
    {
        return [["permission"], ["source"]];
    }

    #[DataProvider("policyAuditChanges")]
    public function testPolicyWritesRollBackWhenDutyOrSourceChangesDuringRequiredAudit(
        string $change,
    ): void {
        [$f, , , $section] = $this->fixture();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:calculation-policy"];
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f, $change, $section): void {
            if ($change === "permission") {
                $f->access->grants = [];
            } else {
                $f->sql->update(
                    "sis_gradebook_calculations",
                    ["revision" => 9],
                    ["id" => $section->id->value],
                );
            }
        });
        $service = $this->service($f, $audit);
        $setup = $service->calculationSetup($section->id);
        $before = $f->sql->rows("sis_history_events");
        try {
            $service->saveCalculation(
                $section->id,
                "points",
                [],
                $setup["revision"],
                gradePolicy: new \Domain\Gradebook\ValueObject\CourseworkGradePolicy([
                    "rounding" => "half_up",
                    "decimal_places" => 0,
                    "cap_at_hundred" => false,
                ]),
            );
            self::fail("A changed policy source or duty committed during audit.");
        } catch (AccessDeniedException | ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_gradebook_calculations"));
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }
}
