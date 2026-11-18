<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CourseworkGradeTest extends TestCase
{
    private function scale(): array
    {
        return [
            [
                "code" => "A",
                "min_score_milli" => 93000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 92999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "F",
                "min_score_milli" => 0,
                "max_score_milli" => 79999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
        ];
    }

    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale($this->scale());
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $book = $f->gradebook();
        $a = new Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional exam",
            10000,
            new DateTimeImmutable("2026-09-01"),
        );
        $book->addAssignment($a);
        $book->score($a->id, $student->id, 9200, 0);
        return [$f, $book, $student, $section, $a];
    }

    private function review(array $fixture): array
    {
        [, $book, $student, $section] = $fixture;
        $choices = $book->gradeChoices($student->id, $section->id);
        self::assertTrue($choices["coursework"]["available"]);
        self::assertArrayNotHasKey("evidence", $choices["coursework"]);
        return $book->reviewFinalGrade(
            $student->id,
            $section->id,
            new EntityId($choices["coursework"]["grade_id"]),
            $choices["current_version"],
            courseworkRevision: $choices["coursework"]["revision"],
        );
    }

    private function evidence(SisFixture $f, EntityId $credit): CourseworkGradeEvidence
    {
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new AcademicCreditId($credit->value),
        );
        foreach ($events as $event) {
            if ($event instanceof FinalGradeWasDecided && $event->metaParam("coursework", null) !== null) {
                return new CourseworkGradeEvidence($event->metaParam("coursework"));
            }
        }
        self::fail("No retained coursework evidence was recorded.");
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testReviewRetainsActualInputsWithoutPostingAutomaticallyOrChangingFinance(
        string $mode,
    ): void {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $setup = $book->calculationSetup($section->id);
        $book->saveCalculation(
            $section->id,
            $mode,
            $mode === "weighted" ? [$a->id->value => 10000] : [],
            $setup["revision"],
            $mode === "category"
                ? [["title" => "Exams", "weight_hundredths" => 10000, "assignments" => [$a->id->value]]]
                : [],
        );
        $events = $f->sql->rows("sis_history_events");
        $finance = $f->financialEvents();
        $review = $this->review($fixture);
        self::assertSame("B", $review["grade"]["code"]);
        self::assertSame("92%", $review["coursework_summary"]["score_display"]);
        self::assertArrayNotHasKey("coursework", $review);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $receipt = $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame(9000, (int) $credit["grade_points_milli"]);
        $evidence = $this->evidence($f, new EntityId($credit["id"]));
        $evidence->verifyCalculation();
        self::assertSame(9200, $evidence->facts["scores"][0][3]);
        self::assertSame($mode, $evidence->facts["mode"]);
        self::assertStringNotContainsString("scores", json_encode($evidence, JSON_THROW_ON_ERROR));
        $book->score($a->id, $student->id, 10000, 1);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        self::assertSame(
            $receipt,
            $book->commitFinalGrade($student->id, $section->id, $review["review_token"]),
        );
        self::assertSame($credit, $f->sql->rows("sis_academic_credits")[0]);
        self::assertSame($finance, $f->financialEvents());
        $history = $book->gradeHistory($student->id, $section->id);
        $decisions = array_values(
            array_filter($history["items"], static fn(array $r): bool => $r["action"] === "grade_decided"),
        );
        self::assertSame(["score_display" => "92%", "mode" => $mode], $decisions[0]["coursework_summary"]);
        self::assertStringNotContainsString("earned_hundredths", json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testScoresAndSetupAreCheckedAtReviewAndAgainAtConfirmation(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $first = $book->gradeChoices($student->id, $section->id)["coursework"];
        $book->score($a->id, $student->id, 9300, 1);
        try {
            $book->reviewFinalGrade(
                $student->id,
                $section->id,
                new EntityId($first["grade_id"]),
                0,
                courseworkRevision: $first["revision"],
            );
            self::fail("A stale displayed suggestion was reviewed.");
        } catch (ValidationException) {
            self::assertNull($f->sql->rows("sis_academic_credits")[0]["grade_id"]);
        }
        $review = $this->review($fixture);
        $events = $f->sql->rows("sis_history_events");
        $book->score($a->id, $student->id, 9400, 2);
        try {
            $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
            self::fail("A changed score with the same recommended grade was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
        $review = $this->review($fixture);
        $setup = $book->calculationSetup($section->id);
        $book->saveCalculation($section->id, "weighted", [$a->id->value => 10000], $setup["revision"]);
        $this->expectException(ConcurrentModificationException::class);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
    }

    public function testExactComparisonDoesNotRoundAcrossAnInstitutionalGradeGap(): void
    {
        [, $book, $student, $section, $a] = $this->fixture();
        $record = $book->assignment($a->id);
        $book->editAssignment($a->id, $a->title, 1000000, $a->dueOn, $record["revision"]);
        $book->score($a->id, $student->id, 929994, 1);
        self::assertSame(9300, $book->summary($student->id, $section->id)["percent_hundredths"]);
        self::assertFalse($book->gradeChoices($student->id, $section->id)["coursework"]["available"]);
        $book->score($a->id, $student->id, 930000, 2);
        self::assertSame("A", $book->gradeChoices($student->id, $section->id)["coursework"]["grade_code"]);
    }

    public function testIncompleteWorkOverOneHundredAndAdministrativeGradesNeverAutoSelect(): void
    {
        [$f, $book, $student, $section, $a] = $this->fixture();
        $b = new Assignment(EntityId::generate(), $section->id, "Pending exam", 10000, $a->dueOn);
        $book->addAssignment($b);
        $choices = $book->gradeChoices($student->id, $section->id);
        self::assertFalse($choices["coursework"]["available"]);
        $manual = $book->reviewFinalGrade(
            $student->id,
            $section->id,
            new EntityId($choices["items"][0]["id"]),
            0,
        );
        self::assertArrayNotHasKey("coursework_summary", $manual);
        $book->score($b->id, $student->id, null, 0, true);
        self::assertTrue($book->gradeChoices($student->id, $section->id)["coursework"]["available"]);
        $record = $book->assignment($a->id);
        $book->editAssignment($a->id, $a->title, 10000, $a->dueOn, $record["revision"], 2000);
        $book->score($a->id, $student->id, 11000, 1);
        self::assertFalse($book->gradeChoices($student->id, $section->id)["coursework"]["available"]);
        self::assertNull($f->sql->rows("sis_academic_credits")[0]["grade_id"]);
    }

    public function testOptionalBonusScoresCanStayPendingAndLaterChangesCannotRewriteTheAcceptedEvidence(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $bonus = new Assignment(EntityId::generate(), $section->id, "Optional work", 0, $a->dueOn, 1000);
        $book->addAssignment($bonus);
        $review = $this->review($fixture);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $credit = new EntityId($f->sql->rows("sis_academic_credits")[0]["id"]);
        $before = $this->evidence($f, $credit)->facts;
        $book->score($bonus->id, $student->id, 1000, 0);
        self::assertSame($before, $this->evidence($f, $credit)->facts);
        self::assertArrayNotHasKey("coursework", $book->gradeChoices($student->id, $section->id));
    }

    public function testRetainedProgramScaleSurvivesLaterGlobalAndProgramChanges(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale($this->scale());
        $program = $this->scale();
        $program[0]["min_score_milli"] = 90000;
        $program[0]["points_milli"] = 5000;
        $program[1]["max_score_milli"] = 89999;
        $f->gradeScale($program, $f->program);
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $book = $f->gradebook();
        $a = new Assignment(
            EntityId::generate(),
            $section->id,
            "Exam",
            10000,
            new DateTimeImmutable("2026-09-01"),
        );
        $book->addAssignment($a);
        $book->score($a->id, $student->id, 9200, 0);
        $f->gradeScale($this->scale(), $f->program);
        $review = $this->review([$f, $book, $student, $section]);
        self::assertSame("A", $review["grade"]["code"]);
        self::assertSame(5000, $review["grade"]["points_milli"]);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        self::assertSame(15000, (int) $f->sql->rows("sis_academic_credits")[0]["grade_points_milli"]);
    }

    public static function failures(): array
    {
        return [["audit"], ["actor"], ["expiry"]];
    }

    #[DataProvider("failures")]
    public function testFinalChecksRollBackTheGradeEvidenceAndReceiptTogether(string $failure): void
    {
        $fixture = [$f, , $student, $section] = $this->fixture();
        $review = $this->review($fixture);
        $before = array_map($f->sql->rows(...), [
            "sis_history_events",
            "sis_history_operations",
            "sis_academic_credits",
        ]);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f, $failure): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional audit failure.");
            }
            if ($failure === "actor") {
                $f->access->actor = EntityId::generate();
            } else {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
        });
        try {
            $f->academicCredits($audit)->commit($student->id, $section->id, $review["review_token"]);
            self::fail("A final command check did not roll back.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                $failure === "audit"
                    ? \RuntimeException::class
                    : ($failure === "actor"
                        ? AccessDeniedException::class
                        : ConcurrentModificationException::class),
                $error::class,
            );
            self::assertSame(
                $before,
                array_map($f->sql->rows(...), [
                    "sis_history_events",
                    "sis_history_operations",
                    "sis_academic_credits",
                ]),
            );
        }
    }

    public function testGradeHistoryReadDoesNotGrantWorkingCourseworkAccessToOtherRegistrars(): void
    {
        [$f, $book, $student, $section] = $this->fixture();
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        self::assertArrayNotHasKey("coursework", $book->gradeChoices($student->id, $section->id));
        $f->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $book->gradeChoices($student->id, $section->id);
    }

    public function testExactCategorySuggestionUsesDropsExcusalsAndBonusWithoutChangingTheirMarks(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $ids = [$a->id->value];
        $work = [
            ["Low quiz", 10000, 4000, 0, false],
            ["Excused", 10000, 0, 0, true],
            ["Bonus", 0, 500, 1000, false],
        ];
        foreach ($work as [$name, $possible, $earned, $bonus, $excused]) {
            $assignment = new Assignment(
                EntityId::generate(),
                $section->id,
                $name,
                $possible,
                $a->dueOn,
                $bonus,
            );
            $book->addAssignment($assignment);
            $book->score($assignment->id, $student->id, $earned, 0, $excused);
            $ids[] = $assignment->id->value;
        }
        $setup = $book->calculationSetup($section->id);
        $book->saveCalculation($section->id, "category", [], $setup["revision"], [
            [
                "title" => "Coursework",
                "weight_hundredths" => 10000,
                "assignments" => $ids,
                "drop_lowest" => 1,
            ],
        ]);
        $scores = $f->sql->rows("sis_scores");
        $review = $this->review($fixture);
        self::assertSame("97%", $review["coursework_summary"]["score_display"]);
        self::assertSame("A", $review["grade"]["code"]);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $this->evidence(
            $f,
            new EntityId($f->sql->rows("sis_academic_credits")[0]["id"]),
        )->verifyCalculation();
        self::assertSame($scores, $f->sql->rows("sis_scores"));
    }

    public function testWeightedBonusAddsItsSeparatePercentagePointsToTheExactRegularAverage(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $b = new Assignment(EntityId::generate(), $section->id, "Short task", 1000, $a->dueOn);
        $bonus = new Assignment(EntityId::generate(), $section->id, "Optional task", 0, $a->dueOn, 1000);
        $book->addAssignment($b);
        $book->addAssignment($bonus);
        $book->score($b->id, $student->id, 900, 0);
        $book->score($bonus->id, $student->id, 500, 0);
        $setup = $book->calculationSetup($section->id);
        $book->saveCalculation(
            $section->id,
            "weighted",
            [$a->id->value => 5000, $b->id->value => 5000, $bonus->id->value => 1000],
            $setup["revision"],
        );
        $review = $this->review($fixture);
        self::assertSame("96%", $review["coursework_summary"]["score_display"]);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $this->evidence(
            $f,
            new EntityId($f->sql->rows("sis_academic_credits")[0]["id"]),
        )->verifyCalculation();
    }

    public function testSuggestedFailingGradeUsesItsPublishedCreditAndGpaChoices(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $book->score($a->id, $student->id, 7000, 1);
        $review = $this->review($fixture);
        self::assertSame("F", $review["grade"]["code"]);
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame(0, (int) $credit["completed_milli"]);
        self::assertSame(0, (int) $credit["grade_points_milli"]);
    }

    public function testWrongEvidenceScopeRollsBackEvenWhenTheOuterReviewNamesTheRightStudent(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->fixture();
        $review = $this->review($fixture);
        $data = json_decode($f->dropCipher->open($review["review_token"]), true, flags: JSON_THROW_ON_ERROR);
        $data["coursework"]["student_id"] = EntityId::generate()->value;
        $token = $f->dropCipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
        $events = $f->sql->rows("sis_history_events");
        try {
            $book->commitFinalGrade($student->id, $section->id, $token);
            self::fail("Another student's coursework evidence was accepted.");
        } catch (ConcurrentModificationException | \Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertNull($f->sql->rows("sis_academic_credits")[0]["grade_id"]);
        }
    }

    public function testMaximumStandardBookRetainsOneBoundedDecision(): void
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        for ($i = 0; $i < 499; ++$i) {
            $id = EntityId::generate()->value;
            $f->sql->insert("sis_assignments", [
                "id" => $id,
                "section_id" => $section->id->value,
                "title" => "Fictional assessment",
                "possible_hundredths" => 10000,
                "due_on" => "2026-09-01",
            ]);
            $f->sql->insert("sis_scores", [
                "id" => EntityId::generate()->value,
                "assignment_id" => $id,
                "student_id" => $student->id->value,
                "earned_hundredths" => 9200,
                "version" => 1,
            ]);
        }
        $setup = $book->calculationSetup($section->id);
        $weights = array_fill_keys(array_column($setup["assignments"], "id"), 20);
        $book->saveCalculation($section->id, "weighted", $weights, $setup["revision"]);
        $review = $this->review($fixture);
        $before = count($f->sql->rows("sis_history_events"));
        $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        self::assertCount($before + 1, $f->sql->rows("sis_history_events"));
        $evidence = $this->evidence($f, new EntityId($f->sql->rows("sis_academic_credits")[0]["id"]));
        self::assertCount(500, $evidence->facts["scores"]);
        $evidence->verifyCalculation();
    }
}
