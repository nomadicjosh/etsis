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

final class GradeComparisonPolicyTest extends TestCase
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
        $book->score($a->id, $student->id, 9250, 0);
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
        $events = EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor(new AcademicCreditId($credit->value));
        foreach ($events as $event) {
            if (
                $event instanceof FinalGradeWasDecided &&
                $event->metaParam("coursework", null) !== null
            ) {
                return new CourseworkGradeEvidence($event->metaParam("coursework"));
            }
        }
        self::fail("No retained coursework evidence was recorded.");
    }

    private function publish(
        SisFixture $f,
        array $rules,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): array {
        $service = $f->comparisonPolicies($audit);
        $r = $service->review(
            $rules,
            $service->view()["version"],
            "Fictional institutional comparison publication.",
        );
        return $service->publish(
            new EntityId($r["publication_id"]),
            $r["rules"],
            $r["version"],
            $r["reason"],
            $r["review_token"],
        );
    }
    private function rounded(): array
    {
        return ["rounding" => "half_up", "decimal_places" => 0, "cap_at_hundred" => false];
    }
    private function facts(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_grade_comparison_policy_state",
            "sis_gradebook_calculations",
            "sis_academic_credits",
            "sis_audit",
        ]);
    }
    public function testDefaultInheritanceAndPublishedRulesUseTheSameProtectedJournal(): void
    {
        [$f, $book, , $section] = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        $setup = $book->calculationSetup($section->id);
        self::assertSame("institution", $setup["grade_policy_scope"]);
        self::assertSame(
            ["scope" => "institution", "publication_id" => null, "version" => 0],
            $setup["grade_policy_reference"],
        );
        $receipt = $this->publish($f, $this->rounded());
        $now = $book->calculationSetup($section->id);
        self::assertEquals($this->rounded(), $now["grade_policy"]);
        self::assertSame(
            $receipt["publication_id"],
            $now["grade_policy_reference"]["publication_id"],
        );
        self::assertSame(1, $now["grade_policy_reference"]["version"]);
        foreach ($before as $row) {
            self::assertSame($row, $f->sql->one("sis_history_events", ["id" => $row["id"]]));
        }
        $events = EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor(
            new \Domain\Gradebook\ValueObject\GradeComparisonPolicyId(
                \Domain\Gradebook\GradeComparisonPolicy::ID,
            ),
        );
        $root = \Domain\Gradebook\GradeComparisonPolicy::reconstituteFromEventStream($events);
        self::assertSame(1, $root->playhead());
        self::assertEquals($this->rounded(), $root->records()[0]["rules"]);
    }
    public function testWeightSavesKeepInheritanceAndAnIdenticalNewPublicationInvalidatesStaleSetup(): void
    {
        [$f, $book, , $section] = $this->fixture();
        $this->publish($f, $this->rounded());
        $v = $book->calculationSetup($section->id);
        $f->access->staff = false;
        $f->access->grants = [];
        $book->saveCalculation(
            $section->id,
            "points",
            [],
            $v["revision"],
            gradePolicy: new \Domain\Gradebook\ValueObject\CourseworkGradePolicy(
                $v["grade_policy"],
            ),
            gradePolicyScope: "institution",
        );
        self::assertNull(
            $f->sql->one("sis_gradebook_calculations", ["id" => $section->id->value])[
                "grade_policy_json"
            ],
        );
        $old = $book->calculationSetup($section->id);
        $f->access->staff = true;
        $this->publish($f, $this->rounded());
        $before = $this->facts($f);
        try {
            $book->saveCalculation($section->id, "points", [], $old["revision"]);
            self::fail("A republished institutional policy did not invalidate the captured setup.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
    }
    public function testExplicitOverridesAndRestoringInheritanceRequireTheIndependentSectionDuty(): void
    {
        [$f, $book, , $section] = $this->fixture();
        $v = $book->calculationSetup($section->id);
        $f->access->staff = false;
        $f->access->grants = [];
        try {
            $book->saveCalculation(
                $section->id,
                "points",
                [],
                $v["revision"],
                gradePolicyScope: "section",
            );
            self::fail("An identical-value pin bypassed policy authority.");
        } catch (AccessDeniedException) {
            self::assertSame(
                "institution",
                $book->calculationSetup($section->id)["grade_policy_scope"],
            );
        }
        $f->access->grants = ["sis:gradebook:calculation-policy"];
        $book->saveCalculation(
            $section->id,
            "points",
            [],
            $v["revision"],
            gradePolicyScope: "section",
        );
        $f->access->staff = true;
        $this->publish($f, $this->rounded());
        $v = $book->calculationSetup($section->id);
        self::assertSame("section", $v["grade_policy_scope"]);
        self::assertSame("none", $v["grade_policy"]["rounding"]);
        $book->saveCalculation(
            $section->id,
            "points",
            [],
            $v["revision"],
            gradePolicyScope: "institution",
        );
        self::assertEquals($this->rounded(), $book->calculationSetup($section->id)["grade_policy"]);
    }
    public function testAcceptedPublicationRetryPreservesItsActorVersionAndOriginalResultAfterLaterPolicy(): void
    {
        [$f] = $this->fixture();
        $service = $f->comparisonPolicies();
        $r = $service->review($this->rounded(), 0, "Fictional retained publication.");
        $args = [
            new EntityId($r["publication_id"]),
            $r["rules"],
            $r["version"],
            $r["reason"],
            $r["review_token"],
        ];
        $receipt = $service->publish(...$args);
        $this->publish($f, \Domain\Gradebook\ValueObject\CourseworkGradePolicy::DEFAULT);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $this->facts($f);
        self::assertSame($receipt, $service->publish(...$args));
        self::assertSame($before, $this->facts($f));
        self::assertSame(2, $service->view()["version"]);
    }
    public function testGlobalPublicationRequiresBothDutiesAndAuditFailuresRollBackTheWholeDecision(): void
    {
        [$f] = $this->fixture();
        $f->access->staff = false;
        foreach (
            [["sis:gradebook:manage"], ["sis:gradebook:calculation-policy:publish"]] as $grants
        ) {
            $f->access->grants = $grants;
            try {
                $f->comparisonPolicies()->view();
                self::fail("One duty granted publication access.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $f->access->staff = true;
        $before = $this->facts($f);
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willThrowException(new \RuntimeException("Fictional required audit failure."));
        try {
            $this->publish($f, $this->rounded(), $audit);
            self::fail("A failed audit accepted policy publication.");
        } catch (\RuntimeException) {
            self::assertSame($before, $this->facts($f));
        }
    }
    public function testInstitutionalPublicationMakesAnUnacceptedSuggestedGradeReviewStale(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->fixture();
        $old = $this->review($fixture);
        self::assertSame("B", $old["grade"]["code"]);
        $this->publish($f, $this->rounded());
        $before = $this->facts($f);
        try {
            $book->commitFinalGrade($student->id, $section->id, $old["review_token"]);
            self::fail("An old source policy was posted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
        self::assertSame("A", $this->review($fixture)["grade"]["code"]);
    }
    public function testPostedGradeRetainsExactPublicationAndInputsAfterLaterInstitutionalRules(): void
    {
        $fixture = [$f, $book, $student, $section] = $this->fixture();
        $publication = $this->publish($f, $this->rounded());
        $r = $this->review($fixture);
        $receipt = $book->commitFinalGrade($student->id, $section->id, $r["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        $e = $this->evidence($f, new EntityId($credit["id"]));
        self::assertSame(
            $publication["publication_id"],
            $e->facts["grade_policy_reference"]["publication_id"],
        );
        self::assertSame(
            ["scope" => "institution", "version" => 1],
            $e->summary()["grade_policy_context"],
        );
        self::assertArrayNotHasKey("publication_id", $e->summary()["grade_policy_context"]);
        $money = $f->financialEvents();
        $this->publish($f, \Domain\Gradebook\ValueObject\CourseworkGradePolicy::DEFAULT);
        $e->verifyCalculation();
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        self::assertSame(
            $receipt,
            $book->commitFinalGrade($student->id, $section->id, $r["review_token"]),
        );
        self::assertSame($credit, $f->sql->rows("sis_academic_credits")[0]);
        self::assertSame($money, $f->financialEvents());
    }
    public function testCounterDisagreementFailsClosedAndCreatesNoHistoricalPolicyObservation(): void
    {
        [$f, $book, , $section] = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        $f->sql->update(
            "sis_grade_comparison_policy_state",
            ["version" => 1],
            ["id" => \Domain\Gradebook\GradeComparisonPolicy::ID],
        );
        try {
            $book->calculationSetup($section->id);
            self::fail("A mismatched policy projection was trusted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }
    public function testPolicyChangeDuringSetupAuditRollsBackBothSetupAndNestedPublication(): void
    {
        [$f, , , $section] = $this->fixture();
        $before = $this->facts($f);
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(function () use ($f): void {
                $this->publish($f, $this->rounded());
            });
        $book = $f->gradebook($audit);
        $v = $book->calculationSetup($section->id);
        try {
            $book->saveCalculation($section->id, "points", [], $v["revision"]);
            self::fail("A changed final policy source was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
    }
    public static function invalidPublicationReviews(): array
    {
        return [["reason"], ["actor"], ["expiry"], ["stale"]];
    }

    #[DataProvider("invalidPublicationReviews")]
    public function testChangedForeignExpiredOrStalePublicationCannotAppend(string $change): void
    {
        [$f] = $this->fixture();
        $service = $f->comparisonPolicies();
        $r = $service->review($this->rounded(), 0, "Fictional prepared institutional policy.");
        if ($change === "reason") {
            $r["reason"] = "Fictional different reason.";
        } elseif ($change === "actor") {
            $f->access->actor = $f->user();
        } elseif ($change === "expiry") {
            $f->clock->time = $f->clock->time->modify("+20 minutes");
        } else {
            $this->publish($f, \Domain\Gradebook\ValueObject\CourseworkGradePolicy::DEFAULT);
        }
        $before = $this->facts($f);
        try {
            $service->publish(
                new EntityId($r["publication_id"]),
                $r["rules"],
                $r["version"],
                $r["reason"],
                $r["review_token"],
            );
            self::fail("An invalid prepared publication appended an event.");
        } catch (ValidationException | AccessDeniedException | ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
    }
}
