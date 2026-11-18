<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\HiatusCase;
use Domain\Student\Service\HiatusLedger;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusCaseId;
use Domain\Student\ValueObject\HiatusFacts;
use Domain\Student\ValueObject\HiatusImpact;
use Domain\Student\ValueObject\HiatusReturnPlan;
use EventStoreFixture;
use HiatusFixture;
use PHPUnit\Framework\TestCase;

final class HiatusLedgerTest extends TestCase
{
    private function fixture(): HiatusFixture
    {
        require_once dirname(__DIR__) . "/Support/HiatusFixture.php";
        return new HiatusFixture();
    }

    private function create(HiatusFixture $h): StudentHiatus
    {
        $case = new StudentHiatus(
            EntityId::generate(),
            $h->sis->student()->id,
            HiatusCode::Leave,
            new DateRange(new DateTimeImmutable("2026-08-01")),
        );
        $h->history->create($case, "Fictional protected case reason.");
        return $case;
    }

    private function stream(StudentHiatus $case): StreamReference
    {
        return new StreamReference(
            new HiatusCaseId($case->id->value),
            "hiatus_case",
        );
    }

    private function events(HiatusFixture $h, StudentHiatus $case): array
    {
        return EventStoreFixture::history(
            $h->sis->connection->queryBuilder(),
        )->page($this->stream($case))->events;
    }

    private function returnPlan(
        array $review,
        bool $reactivate = true,
    ): HiatusReturnPlan {
        return new HiatusReturnPlan(
            new EntityId($review["operation_id"]),
            new DateTimeImmutable($review["return_on"]),
            $review["revision"],
            $reactivate,
            $review["review_token"],
        );
    }

    private function digest(HiatusFixture $h): string
    {
        return hash(
            "sha256",
            json_encode(
                $h->sis->sql->rows("sis_history_events"),
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testCreationAmendmentReturnAndVoidingReplayWithoutRepeatingTheirEffects(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $initial = $h->history->view($case->studentId, $case->id);
        $h->history->end(
            $case->studentId,
            $case->id,
            EntityId::generate(),
            new DateTimeImmutable("2026-08-20"),
            $initial["revision"],
            "Fictional date correction.",
        );
        $return = $h
            ->returns()
            ->review(
                $case->studentId,
                $case->id,
                new DateTimeImmutable("2026-08-10"),
            );
        $h->returns()->commit(
            $case->studentId,
            $case->id,
            $this->returnPlan($return, false),
            "Fictional return confirmation.",
        );
        $view = $h->history->view($case->studentId, $case->id);
        $h->history->void(
            $case->studentId,
            $case->id,
            EntityId::generate(),
            $view["revision"],
            "Fictional void decision.",
        );
        $root = HiatusCase::root(new HiatusCaseId($case->id->value));
        $before = $this->digest($h);
        foreach ($this->events($h, $case) as $stored) {
            $root->acceptHistorical($stored->event);
        }
        self::assertSame(4, $root->playhead());
        self::assertSame("voided", $root->current()["action"]);
        self::assertSame(
            $return["operation_id"],
            $root->current()["return_operation_id"],
        );
        self::assertEquals(
            HiatusFacts::fromHiatus($h->repository->find($case->id))->data,
            $root->current()["facts"],
        );
        self::assertSame(4, $h->repository->historyPosition($case->id));
        self::assertSame($before, $this->digest($h));
        self::assertTrue($initial["history_tracked"]);
        self::assertSame(
            [true, true, true, true],
            array_column(
                $h->history->history($case->studentId, $case->id)["items"],
                "history_tracked",
            ),
        );
    }

    public function testNewReasonsAndCreationFactsAreProtectedWithTrustedActorAndSeparateBusinessDate(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $stored = $this->events($h, $case)[0];
        self::assertSame(
            $h->sis->access->actor->value,
            $stored->context->actor->value,
        );
        self::assertSame(
            "2026-08-01",
            $stored->context->effectiveAt->format("Y-m-d"),
        );
        self::assertSame(
            "Fictional protected case reason.",
            $stored->context->reason,
        );
        self::assertSame("created", $stored->event->payload()["action"]);
        self::assertArrayNotHasKey(
            "note",
            $stored->event->payload()["request"],
        );
        $raw = json_encode(
            [
                $h->sis->sql->rows("sis_history_events"),
                $h->sis->sql->rows("sis_student_hiatus_actions"),
                $h->sis->audit->forSubject($case->id),
            ],
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString(
            "Fictional protected case reason.",
            $raw,
        );
        $row = $h->repository->action($case->id);
        self::assertEquals(
            HiatusLedger::evidencePayload($stored),
            json_decode(
                $h->cipher->open($row["encrypted_content"]),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testEarlierArtifactsRemainUnchangedAndAreObservedOnlyWithAnActualNewDecision(): void
    {
        $h = $this->fixture();
        $f = $h->sis;
        $case = new StudentHiatus(
            EntityId::generate(),
            $f->student()->id,
            HiatusCode::Leave,
            new DateRange(new DateTimeImmutable("2026-08-01")),
        );
        $h->repository->add($case);
        $request = [
            "code" => "LOA",
            "starts_on" => "2026-08-01",
            "ends_on" => null,
            "note" => "Fictional earlier evidence.",
        ];
        $h->repository->addAction(
            $case->id,
            $case->id,
            $f->access->actor,
            "created",
            $h->cipher->seal(
                json_encode(
                    [
                        "impact_result" => null,
                        "request" => $request,
                        "previous_ends_on" => null,
                        "new_ends_on" => null,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            "2026-08-01T08:00:00+00:00",
        );
        $original = $h->repository->action($case->id);
        $before = $this->digest($h);
        $view = $h->history->view($case->studentId, $case->id);
        self::assertFalse($view["history_tracked"]);
        self::assertSame($before, $this->digest($h));
        $h->history->end(
            $case->studentId,
            $case->id,
            EntityId::generate(),
            new DateTimeImmutable("2026-08-20"),
            $view["revision"],
            "Fictional current correction.",
        );
        $events = $this->events($h, $case);
        self::assertCount(2, $events);
        self::assertSame("observed", $events[0]->event->payload()["action"]);
        self::assertSame(
            "2026-08-10",
            $events[0]->context->effectiveAt->format("Y-m-d"),
        );
        self::assertNull($events[0]->event->payload()["request"]);
        self::assertSame("end_changed", $events[1]->event->payload()["action"]);
        self::assertSame($original, $h->repository->action($case->id));
        self::assertSame(
            [true, false],
            array_column(
                $h->history->history($case->studentId, $case->id)["items"],
                "history_tracked",
            ),
        );
    }

    public function testChangedCaseDatesOrCheckpointStopReadsAndMutationsWithoutRepairingFacts(): void
    {
        $fields1 = ["ends_on" => "2026-08-25", "history_position" => 9];
        foreach ($fields1 as $column => $value) {
            $h = $this->fixture();
            $case = $this->create($h);
            $revision = $h->history->view($case->studentId, $case->id)[
            "revision"
            ];
            $h->sis->sql->update(
                "sis_student_hiatuses",
                [$column => $value],
                ["id" => $case->id->value],
            );
            $before = $this->digest($h);
            try {
                $h->history->view($case->studentId, $case->id);
                self::fail("An inconsistent case was displayed as verified.");
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->digest($h));
            }
            try {
                $h->history->end(
                    $case->studentId,
                    $case->id,
                    EntityId::generate(),
                    new DateTimeImmutable("2026-08-26"),
                    $h->repository->find($case->id)->revision(),
                    "Fictional attempted correction.",
                );
                self::fail("An inconsistent case was amended.");
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->digest($h));
            }
        }
    }

    public function testMissingStreamCannotBeReclassifiedAsEarlierUntrackedHistory(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::once())->method("latest")->willReturn(null);
        $history->expects(self::never())->method("append");
        $ledger = new HiatusLedger(
            $h->repository,
            $history,
            $h->cipher,
            $h->sis->clock,
        );
        $this->expectException(CorruptHistoryException::class);
        $ledger->verify($h->repository->find($case->id));
    }

    public function testEncryptedEvidenceCannotDisagreeWithTheAuthoritativeDecision(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $row = $h->repository->action($case->id);
        $data = json_decode(
            $h->cipher->open($row["encrypted_content"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $data["request"]["note"] = "Fictional altered projection note.";
        $h->sis->sql->update(
            "sis_student_hiatus_actions",
            [
                "encrypted_content" => $h->cipher->seal(
                    json_encode($data, JSON_THROW_ON_ERROR),
                ),
            ],
            ["id" => $case->id->value],
        );
        $before = $this->digest($h);
        $this->expectException(CorruptHistoryException::class);
        try {
            $h->history->view($case->studentId, $case->id);
        } finally {
            self::assertSame($before, $this->digest($h));
        }
    }

    public function testExpiredGuidedReviewWritesNoCaseAndNoCourseOrBillingEffects(): void
    {
        $h = $this->fixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $dates = new DateRange(new DateTimeImmutable("2026-08-10"));
        $review = $h
            ->workflow()
            ->review($student->id, HiatusCode::Withdrawal, $dates);
        $f->clock->time = $f->clock->now()->modify("+20 minutes");
        $before = $this->digest($h);
        $this->expectException(ConcurrentModificationException::class);
        try {
            $h->workflow()->commit(
                new StudentHiatus(
                    new EntityId($review["operation_id"]),
                    $student->id,
                    HiatusCode::Withdrawal,
                    $dates,
                ),
                "Fictional expired review.",
                new HiatusImpact(true, $review["revision"], [], $section->id),
                $review["review_token"],
            );
        } finally {
            self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
            self::assertSame($before, $this->digest($h));
            self::assertCount(1, $f->sql->rows("sis_enrollments"));
        }
    }

    public function testReturnReviewIsActorBoundAndExpiredNewConfirmationCannotChangeActivity(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $review = $h
            ->returns()
            ->review(
                $case->studentId,
                $case->id,
                new DateTimeImmutable("2026-08-10"),
            );
        $plan = $this->returnPlan($review);
        $actor = $h->sis->access->actor;
        $h->sis->access->actor = $h->sis->user();
        try {
            $h->returns()->commit(
                $case->studentId,
                $case->id,
                $plan,
                "Fictional wrong actor.",
            );
            self::fail("Another actor used a return review.");
        } catch (AccessDeniedException) {
            self::assertNull($h->repository->returned($case->id));
        }
        $h->sis->access->actor = $actor;
        $h->sis->clock->time = $h->sis->clock->now()->modify("+21 minutes");
        $this->expectException(ConcurrentModificationException::class);
        try {
            $h->returns()->commit(
                $case->studentId,
                $case->id,
                $plan,
                "Fictional expired return.",
            );
        } finally {
            self::assertNull($h->repository->returned($case->id));
            self::assertSame(1, $h->repository->historyPosition($case->id));
        }
    }

    public function testAcceptedReturnRetrySurvivesExpiryAndLaterVoidingWithoutOverwritingThem(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $service = $h->returns();
        $review = $service->review(
            $case->studentId,
            $case->id,
            new DateTimeImmutable("2026-08-10"),
        );
        $plan = $this->returnPlan($review, false);
        $receipt = $service->commit(
            $case->studentId,
            $case->id,
            $plan,
            "Fictional accepted return.",
        );
        $view = $h->history->view($case->studentId, $case->id);
        $h->history->void(
            $case->studentId,
            $case->id,
            EntityId::generate(),
            $view["revision"],
            "Fictional later void.",
        );
        $h->sis->clock->time = $h->sis->clock->now()->modify("+1 day");
        $before = $this->digest($h);
        self::assertSame(
            $receipt,
            $service->commit(
                $case->studentId,
                $case->id,
                $plan,
                "Fictional accepted return.",
            ),
        );
        self::assertTrue($h->repository->find($case->id)->voided);
        self::assertSame($before, $this->digest($h));
    }

    public function testReconstructedEncryptedEvidencePreservesOriginalRetrySemantics(): void
    {
        $h = $this->fixture();
        $case = $this->create($h);
        $stored = $this->events($h, $case)[0];
        $body = HiatusLedger::evidencePayload($stored);
        $h->sis->sql->update(
            "sis_student_hiatus_actions",
            [
                "encrypted_content" => $h->cipher->seal(
                    json_encode($body, JSON_THROW_ON_ERROR),
                ),
            ],
            ["id" => $case->id->value],
        );
        $before = $this->digest($h);
        $h->history->create($case, "Fictional protected case reason.");
        self::assertSame($before, $this->digest($h));
        self::assertSame(1, $h->repository->historyPosition($case->id));
    }

    public function testExpiryDuringFinalAuditRollsBackCaseActivityCourseAndChargeChangesTogether(): void
    {
        $fields2 = ["start", "return"];
        foreach ($fields2 as $purpose) {
            $h = $this->fixture();
            $f = $h->sis;
            $audit = $this->createStub(
                \Domain\Audit\Repository\AuditTrail::class,
            );
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $h,
                    $purpose
): void {
                    $h->sis->audit->record($actor, $action, $subject);
                    if (
                        $action ===
                        ($purpose === "start"
                            ? "student.hiatus_created"
                            : "student.hiatus_returned")
                    ) {
                        $h->sis->clock->time = $h->sis->clock
                            ->now()
                            ->modify("+21 minutes");
                    }
                });
            if ($purpose === "start") {
                $student = $f->student();
                $section = $f->section();
                $f->service->register($student->id, $section->id);
                $dates = new DateRange(new DateTimeImmutable("2026-08-10"));
                $review = $h
                    ->workflow()
                    ->review($student->id, HiatusCode::Withdrawal, $dates);
                $call = fn() => $h
                    ->workflow($audit)
                    ->commit(
                        new StudentHiatus(
                            new EntityId($review["operation_id"]),
                            $student->id,
                            HiatusCode::Withdrawal,
                            $dates,
                        ),
                        "Fictional final audit wait.",
                        new HiatusImpact(
                            true,
                            $review["revision"],
                            [],
                            $section->id,
                        ),
                        $review["review_token"],
                    );
            } else {
                $case = $this->create($h);
                $f->sql->update(
                    "sis_students",
                    ["active" => 0],
                    ["id" => $case->studentId->value],
                );
                $review = $h
                    ->returns()
                    ->review(
                        $case->studentId,
                        $case->id,
                        new DateTimeImmutable("2026-08-10"),
                    );
                $call = fn() => $h
                    ->returns($audit)
                    ->commit(
                        $case->studentId,
                        $case->id,
                        $this->returnPlan($review),
                        "Fictional final return wait.",
                    );
            }
            $before = $this->digest($h);
            $rows = $f->sql->rows("sis_student_hiatuses");
            $enrollments = $f->sql->rows("sis_enrollments");
            $credits = $f->sql->rows("sis_academic_credits");
            $finances = $f->financialEvents();
            try {
                $call();
                self::fail(
                    "A review that expired during its writes was accepted.",
                );
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->digest($h));
                self::assertSame($rows, $f->sql->rows("sis_student_hiatuses"));
                self::assertSame(
                    $enrollments,
                    $f->sql->rows("sis_enrollments"),
                );
                self::assertSame(
                    $credits,
                    $f->sql->rows("sis_academic_credits"),
                );
                self::assertSame($finances, $f->financialEvents());
            }
        }
    }

    public function testHiatusPreviewUsesTheEnrollmentGradingPublicationInsteadOfTodaysReplacement(): void
    {
        $h = $this->fixture();
        $f = $h->sis;
        $grade = [
            "code" => "D",
            "min_score_milli" => 60000,
            "max_score_milli" => 69999,
            "points_milli" => 1000,
            "passes" => true,
            "counts_gpa" => true,
        ];
        $f->gradeScale([$grade]);
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $f->publishDropPolicy(
            $f->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([
                ["through" => null, "grade_code" => "D"],
            ]),
        );
        $f->gradeScale([
            array_replace($grade, [
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ]),
        ]);
        $f->clock->time = new DateTimeImmutable("2026-08-20T12:00:00-07:00");
        $dates = new DateRange(new DateTimeImmutable("2026-08-20"));
        $review = $h
            ->workflow()
            ->review($student->id, HiatusCode::Withdrawal, $dates);
        self::assertSame(
            1000,
            $review["courses"][0]["drop_policy"]["points_milli"],
        );
        self::assertTrue($review["courses"][0]["drop_policy"]["counts_gpa"]);
        $budget = new \Domain\Shared\EventSourcing\HistoryReadBudget(maximumPages: 1);
        $enrollment = $f->registration->enrollment($student->id, $section->id);
        $f->dropPolicies->outcome($f->term, "2026-08-20", $student->id, $enrollment->id, $section->id, $budget);
        try {
            $f->dropPolicies->outcome($f->term, "2026-08-20", $student->id, $enrollment->id, $section->id, $budget);
            self::fail("An attempt preview ignored the shared replay budget.");
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertTrue(true);
        }
        $case = new StudentHiatus(
            new EntityId($review["operation_id"]),
            $student->id,
            HiatusCode::Withdrawal,
            $dates,
        );
        $h->workflow()->commit(
            $case,
            "Fictional retained grading review.",
            new HiatusImpact(false, $review["revision"], [], $section->id),
            $review["review_token"],
        );
        self::assertSame(
            3000,
            (int) $f->sql->rows("sis_academic_credits")[0][
                "grade_points_milli"
            ],
        );
    }

    public function testGuidedCreationRetainsActualDropOperationReferencesAndAllCoupledFacts(): void
    {
        $h = $this->fixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $dates = new DateRange(new DateTimeImmutable("2026-08-10"));
        $review = $h
            ->workflow()
            ->review($student->id, HiatusCode::Withdrawal, $dates);
        $case = new StudentHiatus(
            new EntityId($review["operation_id"]),
            $student->id,
            HiatusCode::Withdrawal,
            $dates,
        );
        $impact = new HiatusImpact(true, $review["revision"], [], $section->id);
        $receipt = $h
            ->workflow()
            ->commit(
                $case,
                "Fictional guided withdrawal.",
                $impact,
                $review["review_token"],
            );
        $p = $this->events($h, $case)[0]->event->payload();
        self::assertEquals($receipt["impact"], $p["result"]);
        $op = $p["result"]["course_changes"][0]["drop_operation_id"];
        self::assertCount(
            1,
            $f->sql->rows("sis_course_drop_receipts", ["id" => $op]),
        );
        self::assertFalse($f->admissions->student($student->id)->active);
        self::assertSame([], $f->sql->rows("sis_enrollments"));
        $f->clock->time = $f->clock->now()->modify("+1 day");
        $before = $this->digest($h);
        self::assertSame(
            $receipt,
            $h
                ->workflow()
                ->commit(
                    $case,
                    "Fictional guided withdrawal.",
                    $impact,
                    $review["review_token"],
                ),
        );
        self::assertSame($before, $this->digest($h));
    }
}
