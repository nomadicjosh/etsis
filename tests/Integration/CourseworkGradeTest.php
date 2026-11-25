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
    private function categoryFixture(): array
    {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $b = new Assignment(EntityId::generate(), $section->id, 'Fictional second exam', 10000, $a->dueOn);
        $bonus = new Assignment(EntityId::generate(), $section->id, 'Fictional bonus', 0, $a->dueOn, 10000);
        $book->addAssignment($b);
        $book->addAssignment($bonus);
        $book->score($b->id, $student->id, 10000, 0);
        $book->score($bonus->id, $student->id, 10000, 0);
        $categories = [['title' => 'Fictional exams', 'weight_hundredths' => 10000,
            'assignments' => [$a->id->value, $b->id->value, $bonus->id->value], 'drop_lowest' => 1,
            'protected_assignments' => [$a->id->value], 'cap_at_hundred' => true]];
        $book->saveCalculation(
            $section->id,
            'category',
            [],
            $book->calculationSetup($section->id)['revision'],
            $categories
        );
        return $fixture;
    }

    public function testAcceptedCategoryRulesAreRetainedAndRecalculateAfterLaterWorkingChanges(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->categoryFixture();
        $finance = $f->financialEvents();
        $review = $this->review($fixture);
        self::assertSame('A', $review['grade']['code']);
        self::assertSame(
            ['capped_categories' => 1, 'protected_assignments' => 1],
            $review['coursework_summary']['category_rules']
        );
        $receipt = $book->commitFinalGrade($student->id, $section->id, $review['review_token']);
        $credit = new EntityId($f->sql->rows('sis_academic_credits')[0]['id']);
        $evidence = $this->evidence($f, $credit);
        $facts = $evidence->facts;
        $evidence->verifyCalculation();
        self::assertSame('100', $facts['exact_percent']);
        self::assertTrue($facts['categories'][0]['cap_at_hundred']);
        self::assertCount(1, $facts['categories'][0]['protected_assignments']);
        $book->saveCalculation($section->id, 'points', [], $book->calculationSetup($section->id)['revision']);
        $f->clock->time = $f->clock->time->modify('+1 day');
        self::assertSame($receipt, $book->commitFinalGrade($student->id, $section->id, $review['review_token']));
        self::assertSame($facts, $this->evidence($f, $credit)->facts);
        $this->evidence($f, $credit)->verifyCalculation();
        self::assertSame($finance, $f->financialEvents());
        $history = $book->gradeHistory($student->id, $section->id);
        $decisions = array_values(array_filter($history['items'], static fn(array $r): bool =>
            $r['action'] === 'grade_decided'));
        self::assertSame(
            $review['coursework_summary']['category_rules'],
            $decisions[0]['coursework_summary']['category_rules']
        );
    }

    public function testChangingProtectionRequiresFreshReviewEvenWhenTheSuggestedLetterStaysTheSame(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->categoryFixture();
        $review = $this->review($fixture);
        $setup = $book->calculationSetup($section->id);
        $categories = $setup['categories'];
        unset($categories[0]['protected_assignments']);
        $book->saveCalculation($section->id, 'category', [], $setup['revision'], $categories);
        self::assertSame('A', $book->gradeChoices($student->id, $section->id)['coursework']['grade_code']);
        $before = EventStoreFixture::historyDigest($f->sql);
        try {
            $book->commitFinalGrade($student->id, $section->id, $review['review_token']);
            self::fail('An old category-policy review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, EventStoreFixture::historyDigest($f->sql));
        }
    }

    public function testRemovingCategoryCapInvalidatesTheOldReviewAndDoesNotPostTheUncappedResult(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->categoryFixture();
        $review = $this->review($fixture);
        $setup = $book->calculationSetup($section->id);
        $categories = $setup['categories'];
        unset($categories[0]['cap_at_hundred']);
        $book->saveCalculation($section->id, 'category', [], $setup['revision'], $categories);
        self::assertFalse($book->gradeChoices($student->id, $section->id)['coursework']['available']);
        $before = EventStoreFixture::historyDigest($f->sql);
        try {
            $book->commitFinalGrade($student->id, $section->id, $review['review_token']);
            self::fail('An earlier capped result was accepted after the cap was removed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, EventStoreFixture::historyDigest($f->sql));
        }
    }

    public function testCategoryRuleDriftDuringRequiredGradeAuditRollsBackTheWholeDecision(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->categoryFixture();
        $review = $this->review($fixture);
        $tables = ['sis_academic_credits', 'sis_history_events', 'sis_history_operations',
            'sis_gradebook_calculations'];
        $before = array_map($f->sql->rows(...), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function () use ($f, $book, $section): void {
            $categories = $book->calculationSetup($section->id)['categories'];
            unset($categories[0]['protected_assignments']);
            $f->sql->update(
                'sis_gradebook_calculations',
                ['categories' => json_encode($categories, JSON_THROW_ON_ERROR)],
                ['id' => $section->id->value]
            );
        });
        try {
            $f->academicCredits($audit)->commit($student->id, $section->id, $review['review_token']);
            self::fail('Category changes during required audit escaped final validation.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }

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
            array_filter(
                $history["items"],
                static fn(array $r): bool => $r["action"] === "grade_decided",
            ),
        );
        self::assertSame(
            [
                "score_display" => "92%",
                "grade_policy" => \Domain\Gradebook\ValueObject\CourseworkGradePolicy::DEFAULT,
                "grading_display" => "92%",
                "mode" => $mode,
            ],
            $decisions[0]["coursework_summary"],
        );
        self::assertStringNotContainsString(
            "earned_hundredths",
            json_encode($history, JSON_THROW_ON_ERROR),
        );
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
        return [["audit"], ["actor"], ["expiry"], ["policy"]];
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
        $audit->method("record")->willReturnCallback(function () use ($f, $failure, $section): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional audit failure.");
            }
            if ($failure === "actor") {
                $f->access->actor = EntityId::generate();
            } elseif ($failure === "policy") {
                $f->sql->insert("sis_gradebook_calculations", [
                    "id" => $section->id->value,
                    "mode" => "points",
                    "weights" => "[]",
                    "revision" => 1,
                    "grade_policy_json" => '{"rounding":"half_up","decimal_places":0,"cap_at_hundred":true}',
                ]);
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
    #[DataProvider("modes")]
    public function testPolicyChangeStalesUnacceptedReviewsAndRetainsAcceptedBasisAndOriginalRetries(
        string $mode,
    ): void {
        $fixture = [$f, $book, $student, $section, $a] = $this->fixture();
        $book->score($a->id, $student->id, 9250, 1);
        $old = $this->review($fixture);
        $setup = $book->calculationSetup($section->id);
        $policy = new \Domain\Gradebook\ValueObject\CourseworkGradePolicy([
            "rounding" => "half_up",
            "decimal_places" => 0,
            "cap_at_hundred" => false,
        ]);
        $book->saveCalculation(
            $section->id,
            $mode,
            $mode === "weighted" ? [$a->id->value => 10000] : [],
            $setup["revision"],
            $mode === "category"
                ? [
                    [
                        "title" => "Exams",
                        "weight_hundredths" => 10000,
                        "assignments" => [$a->id->value],
                    ],
                ]
                : [],
            $policy,
        );
        $events = $f->sql->rows("sis_history_events");
        $money = $f->financialEvents();
        try {
            $book->commitFinalGrade($student->id, $section->id, $old["review_token"]);
            self::fail("A policy change must invalidate an unaccepted grade review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
        $review = $this->review($fixture);
        self::assertSame("A", $review["grade"]["code"]);
        self::assertSame("92.5%", $review["coursework_summary"]["score_display"]);
        self::assertSame("93%", $review["coursework_summary"]["grading_display"]);
        $receipt = $book->commitFinalGrade($student->id, $section->id, $review["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        $evidence = $this->evidence($f, new EntityId($credit["id"]));
        $evidence->verifyCalculation();
        self::assertEquals($policy->facts, $evidence->facts["grade_policy"]);
        self::assertSame("93", $evidence->facts["grading_percent"]);
        self::assertSame("185/2", $evidence->facts["exact_percent"]);
        self::assertSame(12000, (int) $credit["grade_points_milli"]);
        $setup = $book->calculationSetup($section->id);
        $book->saveCalculation(
            $section->id,
            "points",
            [],
            $setup["revision"],
            gradePolicy: new \Domain\Gradebook\ValueObject\CourseworkGradePolicy(),
        );
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        self::assertSame(
            $receipt,
            $book->commitFinalGrade($student->id, $section->id, $review["review_token"]),
        );
        self::assertSame($credit, $f->sql->rows("sis_academic_credits")[0]);
        self::assertSame($money, $f->financialEvents());
        $decisions = array_values(
            array_filter(
                $book->gradeHistory($student->id, $section->id)["items"],
                static fn(array $row): bool => $row["action"] === "grade_decided",
            ),
        );
        self::assertSame($policy->facts, $decisions[0]["coursework_summary"]["grade_policy"]);
        self::assertSame("93%", $decisions[0]["coursework_summary"]["grading_display"]);
        self::assertStringNotContainsString("scores", json_encode($decisions, JSON_THROW_ON_ERROR));
    }

    public function testExplicitCapEnablesBonusSuggestionWithoutChangingWorkingAverageOrOwnerScope(): void
    {
        [$f, $book, $student, $section, $a] = $this->fixture();
        $assignment = $book->assignment($a->id);
        $book->editAssignment($a->id, $a->title, 10000, $a->dueOn, $assignment["revision"], 2000);
        $book->score($a->id, $student->id, 11000, 1);
        self::assertFalse(
            $book->gradeChoices($student->id, $section->id)["coursework"]["available"],
        );
        $setup = $book->calculationSetup($section->id);
        $policy = new \Domain\Gradebook\ValueObject\CourseworkGradePolicy([
            "rounding" => "none",
            "decimal_places" => null,
            "cap_at_hundred" => true,
        ]);
        $book->saveCalculation(
            $section->id,
            "points",
            [],
            $setup["revision"],
            gradePolicy: $policy,
        );
        $suggestion = $book->gradeChoices($student->id, $section->id)["coursework"];
        self::assertTrue($suggestion["available"]);
        self::assertSame("110%", $suggestion["score_display"]);
        self::assertSame("100%", $suggestion["grading_display"]);
        self::assertSame("A", $suggestion["grade_code"]);
        $f->access->staff = false;
        $f->access->grants = [];
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $summary = $book->summary($student->id, $section->id);
        self::assertSame(11000, $summary["percent_hundredths"]);
        self::assertSame($policy->facts, $summary["grade_policy"]);
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $book->summary($student->id, $section->id);
    }
}
