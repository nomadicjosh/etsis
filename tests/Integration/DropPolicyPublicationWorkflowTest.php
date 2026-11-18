<?php

declare(strict_types=1);

namespace Tests\Integration;

use SisFixture;
use EventStoreFixture;
use DateTimeImmutable;
use RuntimeException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\Service\DropPolicyService;
use Domain\Registration\ValueObject\DropPolicyPublicationId;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class DropPolicyPublicationWorkflowTest extends TestCase
{
    private function fixture(): SisFixture
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->legacyGrade(
            new GradeDefinition(EntityId::generate(), "W", 0, false, false),
        );
        return $f;
    }

    private function review(SisFixture $f, ?int $version = null): array
    {
        return $f->dropPolicies->review(
            $f->term,
            $version ?? $f->dropPolicies->show($f->term)["version"],
            new DropWindows([["through" => null, "grade_code" => "W"]]),
            "Fictional approved term policy.",
        );
    }

    private function service(
        SisFixture $f,
        ?AuditTrail $audit = null,
        ?HistoricalEventStore $history = null,
    ): DropPolicyService {
        return new DropPolicyService(
            new \Infrastructure\Persistence\Repository\SqlDropPolicyRepository(
                $f->sql,
                $f->gradeCatalog(),
            ),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->academicCredits(),
            $history ??
                EventStoreFixture::history($f->connection->queryBuilder()),
            $f->dropCipher,
            $f->clock,
        );
    }

    private function page(SisFixture $f): \Domain\Shared\EventSourcing\EventPage
    {
        return EventStoreFixture::history($f->connection->queryBuilder())->page(
            new StreamReference(
                new DropPolicyPublicationId($f->term->value),
                "term_drop_policy",
            ),
        );
    }

    public function testReviewWritesNothingAndConfirmationRetainsTrustedContextAndExactDefinition(): void
    {
        $f = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        $review = $this->review($f);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_term_drop_policies"));
        $f->clock->time = $f->clock->time->modify("+3 minutes");
        $saved = $f->dropPolicies->commit($f->term, $review["review_token"]);
        self::assertSame(0, $saved["version"]);
        $event = $this->page($f)->events[0];
        self::assertSame(
            $f->access->actor->value,
            $event->context->actor->value,
        );
        self::assertSame(
            $f->clock->now()->format(DATE_ATOM),
            $event->context->effectiveAt->format(DATE_ATOM),
        );
        self::assertSame(
            "Fictional approved term policy.",
            $event->context->reason,
        );
        self::assertNull($event->event->payload()["observed_previous_policy"]);
        self::assertSame(
            "2026-08-15",
            $event->event->payload()["definition"]["grace_ends_on"],
        );
        self::assertNotNull($f->dropPolicies->show($f->term)["published"]);
        $summary = $f->dropPolicies->history($f->term)["items"][0];
        self::assertArrayNotHasKey("reason", $summary);
        self::assertArrayNotHasKey("actor_id", $summary);
    }

    public function testAcceptedRetryReturnsOriginalAfterLaterPublicationAndExpiryWithoutChangingNewPolicy(): void
    {
        $f = $this->fixture();
        $first = $this->review($f);
        $original = $f->dropPolicies->commit($f->term, $first["review_token"]);
        $second = $this->review($f);
        $f->dropPolicies->commit($f->term, $second["review_token"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(
            $original,
            $f->dropPolicies->commit($f->term, $first["review_token"]),
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame(1, $f->dropPolicies->show($f->term)["version"]);
    }

    public function testCompetingReviewCannotOverwriteFirstConfirmation(): void
    {
        $f = $this->fixture();
        $first = $this->review($f);
        $second = $this->review($f);
        $f->dropPolicies->commit($f->term, $first["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        try {
            $f->dropPolicies->commit($f->term, $second["review_token"]);
        } finally {
            self::assertCount(1, $this->page($f)->events);
            self::assertSame(0, $f->dropPolicies->show($f->term)["version"]);
        }
    }

    public function testUnconfirmedExpiredAndChangedCalendarOrGradeReviewsWriteNothing(): void
    {
        foreach (["expiry", "clock", "calendar", "grade"] as $change) {
            $f = $this->fixture();
            $review = $this->review($f);
            if ($change === "expiry") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            } elseif ($change === "clock") {
                $f->clock->time = $f->clock->time->modify("-1 minute");
            } elseif ($change === "calendar") {
                $f->sql->update(
                    "sis_terms",
                    ["add_drop_ends_on" => "2026-08-16"],
                    ["id" => $f->term->value],
                );
            } else {
                $f->sql->update(
                    "sis_grades",
                    ["points_milli" => 500],
                    ["code" => "W"],
                );
            }
            $before = $f->sql->rows("sis_history_events");
            try {
                $f->dropPolicies->commit($f->term, $review["review_token"]);
                self::fail(
                    "A changed or expired review was accepted: " . $change,
                );
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_term_drop_policies"));
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testActorAndCurrentCatalogAuthorityRemainRequiredForConfirmationAndHistory(): void
    {
        $f = $this->fixture();
        $review = $this->review($f);
        $original = $f->access->actor;
        $f->access->actor = $f->user();
        try {
            $f->dropPolicies->commit($f->term, $review["review_token"]);
            self::fail("Another staff member confirmed the review.");
        } catch (AccessDeniedException) {
            self::assertSame([], $this->page($f)->events);
        }
        $f->access->actor = $original;
        $f->access->staff = false;
        $calls = [
            fn() => $f->dropPolicies->commit($f->term, $review["review_token"]),
            fn() => $f->dropPolicies->history($f->term),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail("Missing catalog authority was accepted.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->page($f)->events);
            }
        }
    }

    public function testMissingReasonOrInvalidTokenCannotPublish(): void
    {
        $f = $this->fixture();
        foreach (["", "\x01", str_repeat("x", 1001)] as $reason) {
            try {
                $f->dropPolicies->review(
                    $f->term,
                    -1,
                    new DropWindows([["through" => null, "grade_code" => "W"]]),
                    $reason,
                );
                self::fail("An invalid reason was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $this->page($f)->events);
            }
        }
        $this->expectException(ValidationException::class);
        $f->dropPolicies->commit($f->term, "fictional-invalid-token");
    }

    public function testExistingUntrackedPeriodsAreObservedOnlyAtActualPublication(): void
    {
        $f = $this->fixture();
        $windows = [
            ["through" => "2026-09-01", "grade_code" => "W"],
            ["through" => null, "grade_code" => "W"],
        ];
        $f->sql->insert("sis_term_drop_policies", [
            "id" => EntityId::generate()->value,
            "term_id" => $f->term->value,
            "version" => 7,
            "windows" => json_encode($windows, JSON_THROW_ON_ERROR),
        ]);
        self::assertNull($f->dropPolicies->show($f->term)["published"]);
        self::assertSame([], $this->page($f)->events);
        self::assertNull(
            $f->dropPolicies->outcome($f->term, "2026-09-10")[
                "policy_reference"
            ],
        );
        $review = $this->review($f);
        $f->dropPolicies->commit($f->term, $review["review_token"]);
        $p = $this->page($f)->events[0]->event->payload();
        self::assertEquals(
            ["version" => 7, "windows" => $windows],
            $p["observed_previous_policy"],
        );
        self::assertSame(8, $p["version"]);
        self::assertNull($p["previous_publication_id"]);
    }

    public function testPublishedDeadlineSurvivesTermEditsAndEarlyDropUsesTheSameRetainedPolicy(): void
    {
        $f = $this->fixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $review = $this->review($f);
        $saved = $f->dropPolicies->commit($f->term, $review["review_token"]);
        // The live deadline moves earlier; the reviewed deadline must still govern both ledgers.
        $f->sql->update(
            "sis_terms",
            ["add_drop_ends_on" => "2026-08-11"],
            ["id" => $f->term->value],
        );
        $f->clock->time = new DateTimeImmutable("2026-08-12T10:00:00-07:00");
        self::assertTrue($f->dropPolicies->show($f->term)["calendar_changed"]);
        $drop = $f->drops->review($student->id, $section->id);
        self::assertTrue($drop["outcome"]["delete_records"]);
        self::assertSame(
            $saved["publication_id"],
            $drop["outcome"]["policy_reference"]["publication_id"],
        );
        $receipt = $f->drops->commit(
            $student->id,
            $section->id,
            $enrollment->id,
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        self::assertTrue($receipt["academic_records_removed"]);
        self::assertSame("reversed", $receipt["course_charge"]);
        self::assertSame([], $f->sql->rows("sis_enrollments"));
        self::assertSame([], $f->sql->rows("sis_academic_credits"));
        $stream = new StreamReference(
            new \Domain\AcademicRecord\ValueObject\AcademicCreditId(
                $drop["credit"]["id"],
            ),
            "academic_credit",
        );
        $events = EventStoreFixture::history(
            $f->connection->queryBuilder(),
        )->page($stream)->events;
        $last = $events[count($events) - 1]->event;
        self::assertSame(
            $saved["publication_id"],
            $last->metaParam("drop_policy")["publication_id"],
        );
        self::assertSame(8, count($last->payload()["outcome"]));
    }

    public function testSameVisiblePeriodsRepublishedInvalidatePendingDropWithoutChangingEarlierPolicy(): void
    {
        $f = $this->fixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $drop = $f->drops->review($student->id, $section->id);
        $first = $this->page($f)->events[0]->hash;
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        try {
            $f->drops->commit(
                $student->id,
                $section->id,
                $enrollment->id,
                $drop["version"],
                new EntityId($drop["operation_id"]),
                $drop["revision"],
                $drop["effective_on"],
            );
        } finally {
            self::assertSame($first, $this->page($f)->events[0]->hash);
            self::assertSame([], $f->sql->rows("sis_course_drop_receipts"));
        }
    }

    public function testEventProjectionAndAuditAreOneTransaction(): void
    {
        $f = $this->fixture();
        $review = $this->review($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(
                new RuntimeException("Fictional audit interruption."),
            );
        $before = $f->sql->rows("sis_history_events");
        try {
            $this->service($f, $audit)->commit(
                $f->term,
                $review["review_token"],
            );
            self::fail("The interrupted publication succeeded.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit interruption.", $e->getMessage());
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_term_drop_policies"));
        }
        self::assertSame(
            0,
            $f->dropPolicies->commit($f->term, $review["review_token"])[
                "version"
            ],
        );
    }

    public function testOrdinaryPolicyResolutionVerifiesOneHeadWithoutReplayingPublicationHistory(): void
    {
        $f = $this->fixture();
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $store = EventStoreFixture::history($f->connection->queryBuilder());
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method("page");
        $history
            ->expects(self::once())
            ->method("latest")
            ->willReturn(
                $store->latest(
                    new StreamReference(
                        new DropPolicyPublicationId($f->term->value),
                        "term_drop_policy",
                    ),
                ),
            );
        self::assertNotNull(
            $this->service($f, history: $history)->outcome(
                $f->term,
                "2026-08-12",
            )["policy_reference"],
        );
    }

    public function testTimezoneChangesRequireFreshPublicationBeforeAnotherDrop(): void
    {
        $f = $this->fixture();
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $f->clock->time = $f->clock->time->setTimezone(
            new \DateTimeZone("UTC"),
        );
        self::assertTrue($f->dropPolicies->show($f->term)["calendar_changed"]);
        try {
            $f->dropPolicies->outcome($f->term, "2026-08-12");
            self::fail(
                "A changed timezone silently reinterpreted the deadline.",
            );
        } catch (ValidationException) {
            self::assertCount(1, $this->page($f)->events);
        }
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        self::assertTrue(
            $f->dropPolicies->outcome($f->term, "2026-08-12")["delete_records"],
        );
    }

    public function testHistoryPagesFreezeTheirHeadAndDoNotIncludeLaterPublications(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 21; $i++) {
            $f->dropPolicies->commit(
                $f->term,
                $this->review($f)["review_token"],
            );
        }
        $first = $f->dropPolicies->history($f->term);
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_cursor"]);
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $last = $f->dropPolicies->history($f->term, $first["next_cursor"]);
        self::assertCount(1, $last["items"]);
        self::assertSame(20, $last["items"][0]["version"]);
        self::assertNull($last["next_cursor"]);
    }

    public function testProjectionDriftBlocksReadsAndCommandsWithoutRepairOrAppend(): void
    {
        $f = $this->fixture();
        $f->dropPolicies->commit($f->term, $this->review($f)["review_token"]);
        $f->sql->update(
            "sis_term_drop_policies",
            ["windows" => '[{"through":null,"grade_code":"D"}]'],
            ["term_id" => $f->term->value],
        );
        $before = $f->sql->rows("sis_history_events");
        $calls = [
            fn() => $f->dropPolicies->show($f->term),
            fn() => $f->dropPolicies->outcome($f->term, "2026-08-12"),
            fn() => $this->review($f),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail("A changed projection was accepted.");
            } catch (CorruptHistoryException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
        }
    }
}
