<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use PHPUnit\Framework\TestCase;

final class AcademicCreditHistoryTest extends TestCase
{
    private function setupCredit(bool $legacy = false): array
    {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
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
        ]);
        $student = $f->student();
        $section = $f->section(faculty: $f->access->actor);
        if ($legacy) {
            // Explicit fictional old-state fixture: no journal rows are removed or fabricated.
            $enrollment = EntityId::generate();
            $id = EntityId::generate();
            $f->sql->insert("sis_enrollments", [
                "id" => $enrollment->value,
                "student_id" => $student->id->value,
                "section_id" => $section->id->value,
                "term_id" => $f->term->value,
                "status" => "A",
                "registered_at" => "2026-08-01T17:00:00.000000Z",
                "status_at" => "2026-08-01T17:00:00.000000Z",
                "version" => 0,
            ]);
            $f->sql->insert("sis_academic_credits", [
                "id" => $id->value,
                "student_id" => $student->id->value,
                "course_id" => $section->courseId->value,
                "term_id" => $f->term->value,
                "source_id" => $enrollment->value,
                "credit_type" => "institutional",
                "course_code" => "CS.101",
                "course_title" => "Observed existing course",
                "attempted_milli" => 3000,
                "completed_milli" => 0,
                "grade_points_milli" => 0,
                "grade_id" => null,
                "status" => "A",
                "version" => 0,
            ]);
        } else {
            $enrollment = $f->service->register($student->id, $section->id)->id;
            $id = new EntityId(
                $f->sql->one("sis_academic_credits", [
                    "source_id" => $enrollment->value,
                ])["id"],
            );
        }
        $grades = $f->academicCredits()->choices($student->id, $section->id, $enrollment)["items"];
        return [
            $f,
            $student->id,
            $section->id,
            $enrollment,
            $id,
            new EntityId($grades[0]["id"]),
            new EntityId($grades[1]["id"]),
        ];
    }
    private function replay(SisFixture $f, EntityId $id): AcademicCredit
    {
        $store = EventStoreFixture::framework($f->connection->queryBuilder());
        $root = AcademicCredit::root(new AcademicCreditId($id->value));
        foreach ($store->getAggregateHistoryFor(new AcademicCreditId($id->value)) as $e) {
            $root->acceptHistorical($e);
        }
        return $root;
    }
    public function testReviewIsReadOnlyAndReplayedFactsProduceTheCurrentProjection(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit();
        $ledger = $f->academicCredits();
        $events = $f->sql->rows("sis_history_events");
        $review = $ledger->review($student, $section, $enrollment, $a, 0);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $receipt = $ledger->commit($student, $section, $review["review_token"]);
        self::assertSame(2, $receipt["last_version"]);
        $root = $this->replay($f, $id);
        self::assertSame("registration", $root->provenance());
        self::assertSame(12000, $root->credit()["grade_points_milli"]);
        self::assertEquals($root->credit(), new SqlAcademicCreditRepository($f->sql)->projection($id));
        self::assertSame($events, array_slice($f->sql->rows("sis_history_events"), 0, count($events)));
        // Protected history does not expose the grade-review reason in stored JSON.
        self::assertStringNotContainsString("Initial final grade", json_encode($f->sql->rows("sis_history_events")));
    }
    public function testExactRetryCannotOverwriteALaterCorrection(): void
    {
        [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit();
        $ledger = $f->academicCredits();
        $first = $ledger->review($student, $section, $enrollment, $a, 0);
        $receipt = $ledger->commit($student, $section, $first["review_token"]);
        $second = $ledger->review(
            $student,
            $section,
            $enrollment,
            $b,
            1,
            reason: "Approved recalculation of the final assessment.",
        );
        $ledger->commit($student, $section, $second["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+2 days");
        self::assertSame($receipt, $ledger->commit($student, $section, $first["review_token"]));
        // Earlier accepted tokens had no expiry. Their original receipt still verifies without
        // rewriting its event, context, fingerprint or later grade decision.
        $old = json_decode($f->dropCipher->open($first["review_token"]), true, 16, JSON_THROW_ON_ERROR);
        unset($old["expires_at"]);
        $oldToken = $f->dropCipher->seal(json_encode($old, JSON_THROW_ON_ERROR));
        self::assertSame($receipt, $ledger->commit($student, $section, $oldToken));
        self::assertSame($b->value, $f->sql->one("sis_academic_credits", ["id" => $id->value])["grade_id"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
        self::assertSame(3, $this->replay($f, $id)->playhead());
    }

    public function testExpiredGradeReviewsRollBackObservedAndTrackedFactsAndCanBeReviewedAgain(): void
    {
        foreach ([false, true] as $legacy) {
            [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit($legacy);
            $ledger = $f->academicCredits();
            $review = $ledger->review($student, $section, $enrollment, $a, 0);
            self::assertSame($f->clock->now()->getTimestamp() + 1200, $review["expires_at"]);
            $events = $f->sql->rows("sis_history_events");
            $receipts = $f->sql->rows("sis_history_operations");
            $audit = $f->audit->forSubject($id);
            $projection = new SqlAcademicCreditRepository($f->sql)->projection($id);
            $finance = $f->financialEvents();
            $f->clock->time = $f->clock->time->modify("+20 minutes");
            try {
                $ledger->commit($student, $section, $review["review_token"]);
                self::fail("A review remained usable at its expiry boundary.");
            } catch (ConcurrentModificationException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
                self::assertSame($audit, $f->audit->forSubject($id));
                self::assertSame($projection, new SqlAcademicCreditRepository($f->sql)->projection($id));
                self::assertSame($finance, $f->financialEvents());
                if ($legacy) {
                    self::assertNull(new SqlAcademicCreditRepository($f->sql)->mapping($id));
                }
            }
            $refreshed = $ledger->review($student, $section, $enrollment, $a, 0);
            $f->clock->time = $f->clock->time->modify("+1199 seconds");
            $ledger->commit($student, $section, $refreshed["review_token"]);
            self::assertSame($a->value, new SqlAcademicCreditRepository($f->sql)->projection($id)["grade_id"]);
        }
    }

    public function testUnacceptedEarlierGradeReviewWithoutExpiryRequiresFreshReview(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit(true);
        $ledger = $f->academicCredits();
        $review = $ledger->review($student, $section, $enrollment, $a, 0);
        $old = json_decode($f->dropCipher->open($review["review_token"]), true, 16, JSON_THROW_ON_ERROR);
        unset($old["expires_at"]);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        try {
            $ledger->commit($student, $section, $f->dropCipher->seal(json_encode($old, JSON_THROW_ON_ERROR)));
            self::fail("An unaccepted earlier review bypassed expiry.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
            self::assertNull(new SqlAcademicCreditRepository($f->sql)->mapping($id));
        }
    }

    public function testLostAuthorityOrChangedActorAtConfirmationCompletionRollsBackGradeAndAudit(): void
    {
        foreach (["permission", "actor"] as $change) {
            [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit();
            $ledger = $f->academicCredits();
            $initial = $ledger->review($student, $section, $enrollment, $a, 0);
            $ledger->commit($student, $section, $initial["review_token"]);
            $audit = new class ($f, $change) implements AuditTrail {
                public function __construct(private SisFixture $fixture, private string $change) {}
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    $this->fixture->audit->record($actor, $action, $subject);
                    if ($this->change === "permission") {
                        $this->fixture->access->staff = false;
                        $this->fixture->access->grants = [];
                    } else {
                        $this->fixture->access->actor = $this->fixture->user();
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return $this->fixture->audit->forSubject($subject);
                }
            };
            $ledger = $f->academicCredits($audit);
            $review = $ledger->review($student, $section, $enrollment, $b, 1, reason: "Fictional approved correction.");
            $events = $f->sql->rows("sis_history_events");
            $receipts = $f->sql->rows("sis_history_operations");
            $auditBefore = $f->audit->forSubject($id);
            $projection = new SqlAcademicCreditRepository($f->sql)->projection($id);
            $finance = $f->financialEvents();
            try {
                $ledger->commit($student, $section, $review["review_token"]);
                self::fail("Changed authority or actor committed an academic decision.");
            } catch (AccessDeniedException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
                self::assertSame($auditBefore, $f->audit->forSubject($id));
                self::assertSame($projection, new SqlAcademicCreditRepository($f->sql)->projection($id));
                self::assertSame($finance, $f->financialEvents());
            }
        }
    }
    public function testTwoReviewsCannotBothCommitAtTheSameExpectedVersion(): void
    {
        [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit();
        $l = $f->academicCredits();
        $one = $l->review($student, $section, $enrollment, $a, 0);
        $two = $l->review($student, $section, $enrollment, $b, 0);
        $l->commit($student, $section, $one["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        try {
            $l->commit($student, $section, $two["review_token"]);
            self::fail("Stale grade review committed.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }

    public function testTeachingReadsAndGradeConfirmationUseTheDefaultReplayLimitWithoutPartialChanges(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit();
        $ledger = $f->academicCredits();
        $review = $ledger->review($student, $section, $enrollment, $a, 0);
        $root = $this->replay($f, $id);
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $stream = new StreamReference(new AcademicCreditId($id->value), "academic_credit");
        $repo = new SqlAcademicCreditRepository($f->sql);
        // Bounded fictional journal fixture: exercise the real encrypted store and ledger, including
        // the exact 2,000-event boundary, without thousands of separate service/transaction calls.
        $extend = function (int $count) use ($f, $root, $review, $history, $stream, $repo, $section): void {
            $expectedRow = $root->credit()["version"];
            for ($offset = 0; $offset < $count; $offset += 50) {
                $expected = $root->playhead();
                $operation = EntityId::generate();
                $events = [];
                for ($i = 0; $i < min(50, $count - $offset); ++$i) {
                    $event = \Domain\AcademicRecord\Event\FinalGradeWasDecided::occur(
                        $root->aggregateId(),
                        [
                            "decision_id" => EntityId::generate()->value,
                            "previous_decision_id" => $root->decision(),
                            "grade" => $review["grade"],
                            "assignment_id" => null,
                        ],
                        ["__aggregate_type" => "academic_credit"],
                    )->withPlayhead($root->playhead() + 1);
                    $root->acceptHistorical($event);
                    $events[] = $event;
                }
                $history->append(
                    $stream,
                    $operation,
                    $expected,
                    new \Domain\Shared\EventSourcing\EventContext(
                        $f->access->actor,
                        $f->clock->now(),
                        $operation,
                        reason: "Fictional bounded historical fixture.",
                    ),
                    $events,
                );
            }
            $repo->project($root->credit(), $section, $expectedRow);
        };
        $extend(1999);
        self::assertSame(1999, $ledger->choices($student, $section, $enrollment)["current_version"]);
        $pending = $ledger->review($student, $section, $enrollment, $a, 1999, reason: "Fictional review.");
        $extend(1);
        $events = $f->sql->fetch("SELECT COUNT(*) AS total FROM sis_history_events")[0]["total"];
        $receipts = $f->sql->fetch("SELECT COUNT(*) AS total FROM sis_history_operations")[0]["total"];
        $projection = $repo->projection($id);
        $finance = $f->financialEvents();
        $audit = $f->audit->forSubject($id);
        foreach (
            [
                fn() => $ledger->choices($student, $section, $enrollment),
                fn() => $ledger->review($student, $section, $enrollment, $a, 2000, reason: "Fictional review."),
                fn() => $ledger->commit($student, $section, $pending["review_token"]),
                fn() => $ledger->history($student, $section, $enrollment),
            ]
            as $readOrDecision
        ) {
            try {
                $readOrDecision();
                self::fail("A teaching operation bypassed the replay limit.");
            } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
                self::assertSame(
                    $events,
                    $f->sql->fetch("SELECT COUNT(*) AS total FROM sis_history_events")[0]["total"],
                );
                self::assertSame(
                    $receipts,
                    $f->sql->fetch("SELECT COUNT(*) AS total FROM sis_history_operations")[0]["total"],
                );
                self::assertSame($projection, $repo->projection($id));
                self::assertSame($finance, $f->financialEvents());
                self::assertSame($audit, $f->audit->forSubject($id));
                self::assertNotNull($f->registration->enrollment($student, $section));
            }
        }
    }
    public function testFacultyCannotAmendWithoutBothIndependentPermissionsAndAReason(): void
    {
        [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit();
        $l = $f->academicCredits();
        $f->access->staff = false;
        $first = $l->review($student, $section, $enrollment, $a, 0);
        $l->commit($student, $section, $first["review_token"]);
        foreach ([[], ["sis:gradebook:amend"], ["sis:student:read"]] as $grants) {
            $f->access->grants = $grants;
            try {
                $l->review($student, $section, $enrollment, $b, 1, reason: "Recalculated.");
                self::fail("Incomplete authority accepted.");
            } catch (AccessDeniedException) {
                self::assertSame(1, $f->sql->one("sis_academic_credits", ["id" => $id->value])["version"]);
            }
        }
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        try {
            $l->review($student, $section, $enrollment, $b, 1);
            self::fail("Correction accepted without a reason.");
        } catch (ValidationException) {
            self::assertSame(2, $this->replay($f, $id)->playhead());
        }
        $review = $l->review($student, $section, $enrollment, $b, 1, reason: "Approved correction.");
        $f->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $l->commit($student, $section, $review["review_token"]);
    }
    public function testLegacyBaselineIsAnObservationAndAtomicWithTheReviewedGrade(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit(true);
        $fail = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $l = $f->academicCredits($fail);
        $review = $l->review($student, $section, $enrollment, $a, 0, effective: "2026-08-05");
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        try {
            $l->commit($student, $section, $review["review_token"]);
            self::fail("Failed audit committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure.", $e->getMessage());
        }
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
        self::assertNull(new SqlAcademicCreditRepository($f->sql)->mapping($id));
        $l = $f->academicCredits();
        $l->commit($student, $section, $review["review_token"]);
        $page = $l->history($student, $section, $enrollment);
        self::assertTrue($page["earlier_history_unknown"]);
        self::assertSame(["observed_existing", "grade_decided"], array_column($page["items"], "action"));
        self::assertSame("2026-08-10T17:00:00.000000Z", $page["items"][0]["effective_at"]);
        self::assertStringStartsWith("2026-08-05", $page["items"][1]["effective_at"]);
        self::assertArrayNotHasKey("actor_id", $page["items"][0]);
        self::assertArrayNotHasKey("reason", $page["items"][1]);
        self::assertSame("observed_existing", $this->replay($f, $id)->provenance());
    }
    public function testTrackedProjectionTamperingFailsClosed(): void
    {
        [$f, $student, $section, $enrollment, $id] = $this->setupCredit();
        $f->sql->update("sis_academic_credits", ["completed_milli" => 3000], ["id" => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->academicCredits()->choices($student, $section, $enrollment);
    }
    public function testReviewCannotBeUsedByAnotherActorOrForAnotherStudent(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit();
        $l = $f->academicCredits();
        $r = $l->review($student, $section, $enrollment, $a, 0);
        try {
            $l->commit(EntityId::generate(), $section, $r["review_token"]);
            self::fail("Cross-student token accepted.");
        } catch (ValidationException) {
            self::assertSame(1, $this->replay($f, $id)->playhead());
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $l->commit($student, $section, $r["review_token"]);
    }
    public function testEarlyDropRetainsAcademicHistoryAndANewRegistrationUsesANewStream(): void
    {
        [$f, $student, $section, $enrollment, $id] = $this->setupCredit();
        $review = $f->drops->review($student, $section);
        $f->drops->commit(
            $student,
            $section,
            new EntityId($review["enrollment_id"]),
            $review["version"],
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
        self::assertNull(new SqlAcademicCreditRepository($f->sql)->projection($id));
        self::assertSame("D", $this->replay($f, $id)->credit()["status"]);
        self::assertNotNull(new SqlAcademicCreditRepository($f->sql)->mapping($id));
        $again = $f->service->register($student, $section);
        self::assertNotSame($enrollment->value, $again->id->value);
        self::assertNotSame(
            $id->value,
            $f->sql->one("sis_academic_credits", [
                "source_id" => $again->id->value,
            ])["id"],
        );
    }
    public function testEffectiveDateIsBoundedAndTheCapturedUngradedPolicySurvivesPublication(): void
    {
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit();
        $l = $f->academicCredits();
        foreach (["2026-08-11", "2026-08-09", "2026-02-30"] as $date) {
            try {
                $l->review($student, $section, $enrollment, $a, 0, effective: $date);
                self::fail("Invalid effective date accepted.");
            } catch (ValidationException) {
                self::assertSame(1, $this->replay($f, $id)->playhead());
            }
        }
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 5000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        self::assertSame($a->value, $l->choices($student, $section, $enrollment)["items"][0]["id"]);
        $r = $l->review($student, $section, $enrollment, $a, 0);
        $l->commit($student, $section, $r["review_token"]);
        self::assertSame(12000, $this->replay($f, $id)->credit()["grade_points_milli"]);
    }
    public function testCuratedPagingFreezesTheHeadAndProjectionCanBeReconstructedWithoutSideEffects(): void
    {
        [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit();
        $l = $f->academicCredits();
        for ($version = 0; $version < 11; ++$version) {
            $r = $l->review(
                $student,
                $section,
                $enrollment,
                $version % 2 === 0 ? $a : $b,
                $version,
                reason: "Approved assessment review.",
            );
            $l->commit($student, $section, $r["review_token"]);
        }
        $first = $l->history($student, $section, $enrollment);
        self::assertCount(10, $first["items"]);
        self::assertNotNull($first["next_cursor"]);
        $r = $l->review($student, $section, $enrollment, $a, 11, reason: "Later decision.");
        $l->commit($student, $section, $r["review_token"]);
        $last = $l->history($student, $section, $enrollment, $first["next_cursor"]);
        self::assertCount(2, $last["items"]);
        self::assertSame([11, 12], array_column($last["items"], "version"));
        self::assertNull($last["next_cursor"]);
        $root = $this->replay($f, $id);
        $repo = new SqlAcademicCreditRepository($f->sql);
        $before = $repo->projection($id);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $finance = $f->financialEvents();
        // Rebuild proof in this disposable in-memory fixture, never a live-data rebuild command.
        $f->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$id->value]);
        $repo->project($root->credit(), $section, null);
        self::assertEquals($before, $repo->projection($id));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
        self::assertSame($finance, $f->financialEvents());
    }
    public function testOlderPostedFactsRetainTheOriginalGradeWithoutInventingItsAuthor(): void
    {
        [$f, $student, $section, $enrollment, $id, $a, $b] = $this->setupCredit(true);
        $f->sql->update(
            "sis_academic_credits",
            [
                "grade_id" => $a->value,
                "completed_milli" => 3000,
                "grade_points_milli" => 12000,
                "version" => 4,
            ],
            ["id" => $id->value],
        );
        $l = $f->academicCredits();
        $review = $l->review(
            $student,
            $section,
            $enrollment,
            $b,
            4,
            reason: "Correction following a reviewed assessment.",
        );
        $l->commit($student, $section, $review["review_token"]);
        $history = $l->history($student, $section, $enrollment);
        self::assertTrue($history["earlier_history_unknown"]);
        self::assertSame(["A", "B"], array_column($history["items"], "grade_code"));
        self::assertSame(5, $this->replay($f, $id)->credit()["version"]);
    }

    public function testCuratedHistoryRequiresOwnerAssignedFacultyOrAcademicReadAuthority(): void
    {
        [$f, $student, $section, $enrollment] = $this->setupCredit();
        $book = new \Domain\Gradebook\Service\GradebookService(
            new \Infrastructure\Persistence\Repository\SqlGradebookRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->academicCredits(),
            $f->comparisonPolicies(),
        );
        $f->access->staff = false;
        self::assertCount(1, $book->gradeHistory($student, $section)["items"]);
        $f->access->actor = $f->user();
        try {
            $book->gradeHistory($student, $section);
            self::fail("Unrelated user read academic history.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->grants = ["sis:student:read"];
        self::assertCount(1, $book->gradeHistory($student, $section)["items"]);
    }
    public function testCorrectionPermissionIsProtectedIndependentAndMigrationRetryPreservesHistory(): void
    {
        [$f, $student, $section, $enrollment, $id] = $this->setupCredit();
        $permission = $f->sql->one("sis_access_permissions", [
            "name" => "sis:gradebook:amend",
        ]);
        self::assertSame(1, (int) $permission["is_system"]);
        $sis = $f->sql->one("sis_access_permissions", ["name" => "sis"]);
        self::assertSame(
            [],
            $f->sql->rows("sis_access_implications", [
                "ancestor_id" => $sis["id"],
                "descendant_id" => $permission["id"],
            ]),
        );
        $events = $f->sql->rows("sis_history_events");
        $routing = $f->sql->rows("sis_academic_credit_streams");
        $migration = new CreateSisAcademicCreditStreams("retry");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($routing, $f->sql->rows("sis_academic_credit_streams"));
        self::assertSame(
            $permission,
            $f->sql->one("sis_access_permissions", [
                "name" => "sis:gradebook:amend",
            ]),
        );
    }
    public function testLateDropReplaysWithoutReversingTheChargeAndAnEarlyDropRejectsAnUncommittedGrade(): void
    {
        [$f, $student, $section, $enrollment, $id] = $this->setupCredit();
        $finance = $f->financialEvents();
        $f->clock->time = new DateTimeImmutable("2026-09-01T12:00:00-07:00");
        $f->drops->drop($student, $section);
        $root = $this->replay($f, $id);
        self::assertSame("W", $root->credit()["status"]);
        self::assertSame(3000, $root->credit()["attempted_milli"]);
        self::assertSame(0, $root->credit()["completed_milli"]);
        self::assertEquals($root->credit(), new SqlAcademicCreditRepository($f->sql)->projection($id));
        self::assertSame($finance, $f->financialEvents());
        [$f, $student, $section, $enrollment, $id, $a] = $this->setupCredit();
        $l = $f->academicCredits();
        $review = $l->review($student, $section, $enrollment, $a, 0);
        $f->drops->drop($student, $section);
        $events = $f->sql->rows("sis_history_events");
        try {
            $l->commit($student, $section, $review["review_token"]);
            self::fail("An old review revived an early-dropped credit.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertNull(new SqlAcademicCreditRepository($f->sql)->projection($id));
        }
    }
}
