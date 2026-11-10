<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\DegreeAudit\Event\RequirementsWerePublished;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementsPublicationReviewTest extends TestCase
{
    private ReusableGroupFixture $f;

    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
        $this->f->saveDegree();
    }

    private function stream(): StreamReference
    {
        return new StreamReference(
            new RequirementsPublicationId($this->f->degree->value),
            "degree_audit_requirements",
        );
    }

    private function snapshot(): array
    {
        $result = [];
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_degree_audit_templates",
            "sis_audit",
        ];
        foreach ($tables as $table) {
            $result[$table] = $this->f->digest($table);
        }
        return $result;
    }

    private function token(array $proof): string
    {
        unset($proof["review_token"], $proof["course_details"], $proof["group_details"]);
        return $this->f->catalog->fixture->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
    }

    public function testConfirmationUsesItsActualTimeAndDoesNotAddAnotherEventFormat(): void
    {
        $f = $this->f;
        $before = $this->snapshot();
        $review = $f->degrees->review($f->degree, "Fictional timed publication");
        self::assertSame($before, $this->snapshot());
        self::assertSame("degree-audit-publication-review", $review["kind"]);
        self::assertArrayNotHasKey("effective_at", $review);
        self::assertSame($f->sis->clock->now()->getTimestamp() + 1200, $review["expires_at"]);
        $f->sis->clock->time = $f->sis->clock->now()->modify("+3 minutes");
        $receipt = $f->degrees->publish($f->degree, $review["review_token"]);
        $stored = $f->history->page($this->stream())->events[0];
        self::assertSame(
            $f->sis->clock->now()->getTimestamp(),
            $stored->context->effectiveAt->getTimestamp(),
        );
        self::assertSame($f->sis->clock->now()->getTimestamp(), $stored->recordedAt->getTimestamp());
        self::assertSame(4, $stored->event->metaParam("schema_version"));
        self::assertSame($receipt["event_ids"][0], (string) $stored->event->eventId());
        self::assertSame("Fictional timed publication", $stored->context->reason);
    }

    public function testExpiryBoundaryWritesNothingAndFreshReviewSucceeds(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional expired publication");
        $f->sis->clock->time = $f->sis->clock->now()->modify("+20 minutes");
        $before = $this->snapshot();
        try {
            $f->degrees->publish($f->degree, $review["review_token"]);
            self::fail("Expired review accepted.");
        } catch (ConcurrentModificationException $error) {
            self::assertStringContainsString("expired", $error->getMessage());
            self::assertSame($before, $this->snapshot());
        }
        $fresh = $f->degrees->review($f->degree, $review["reason"]);
        self::assertNotSame($review["operation_id"], $fresh["operation_id"]);
        self::assertSame(1, $f->degrees->publish($f->degree, $fresh["review_token"])["last_version"]);
    }

    public function testAcceptedExpiredRetryLeavesLaterPublicationAndAuditUntouched(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional original publication");
        $receipt = $f->degrees->publish($f->degree, $review["review_token"]);
        $f->sis->clock->time = $f->sis->clock->now()->modify("+1 day");
        $definition = $f->definition;
        $definition["name"] = "Fictional later requirements";
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($definition));
        $f->publishDegree();
        $before = $this->snapshot();
        self::assertSame($receipt, $f->degrees->publish($f->degree, $review["review_token"]));
        self::assertSame($before, $this->snapshot());
        self::assertSame(2, $f->degrees->view($f->degree)["publication_position"]);
    }

    public static function earlierFormats(): iterable
    {
        foreach ([1, 2, 3, 4] as $schema) {
            yield "schema $schema" => [$schema];
        }
    }

    #[DataProvider('earlierFormats')]
    public function testEarlierCompletedReviewKeepsItsOriginalTimeAndReceiptButPendingReviewMustRefresh(int $schema): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional earlier publication");
        $review["kind"] = "degree-audit-publication-review.v" . $schema;
        if ($schema < 3) {
            $review["definition"] = $f->oldDefinition($schema === 2);
        }
        if ($schema < 4) {
            unset($review["grade_scale"], $review["program_publication"]);
        }
        unset($review["expires_at"]);
        $context = new EventContext(
            $f->sis->access->actor,
            $f->sis->clock->now(),
            new EntityId($review["operation_id"]),
            reason: $review["reason"],
        );
        $review["effective_at"] = $context->describe()["effective_at"];
        $token = $this->token($review);
        $before = $this->snapshot();
        try {
            $f->degrees->publish($f->degree, $token);
            self::fail("An unconfirmed earlier review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot());
        }
        // A real earlier accepted publication, using the pre-change event contract.
        $labels = $review["context"];
        unset($labels["program_id"], $labels["academic_year_id"]);
        $event = RequirementsWerePublished::occur(
            $this->stream()->aggregateId,
            [
                "program_id" => $f->sis->program->value,
                "academic_year_id" => $f->year->value,
                "version_id" => $review["operation_id"],
                "previous_version_id" => null,
                "labels" => $labels,
                "definition" => $review["definition"],
            ] + ($schema === 4 ? ["grade_scale" => $review["grade_scale"]] : []),
            ["__aggregate_type" => "degree_audit_requirements", "schema_version" => $schema],
        )->withPlayhead(1);
        $receipt = $f->sis->transactions->run(function () use ($f, $review, $context, $event): array {
            $receipt = $f->history->append(
                $this->stream(),
                new EntityId($review["operation_id"]),
                0,
                $context,
                [$event],
            );
            new \Infrastructure\Persistence\Repository\SqlRequirementsRepository($f->sis->sql)->published(
                $f->degree,
                new EntityId($receipt["operation_id"]),
                1,
            );
            return $receipt;
        });
        $f->sis->clock->time = $f->sis->clock->now()->modify("+1 day");
        $f->publishDegree();
        $before = $this->snapshot();
        self::assertSame($receipt, $f->degrees->publish($f->degree, $token));
        self::assertSame($before, $this->snapshot());
        self::assertSame(
            $review["effective_at"],
            $f->history->page($this->stream())->events[0]->context->describe()["effective_at"],
        );
    }

    public function testPublicationDoesNotTrustAnEffectiveTimeOrMalformedExpiryInNewProofs(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional proof validation");
        $cases = [
            array_replace($review, ["effective_at" => "2000-01-01T00:00:00Z"]),
            array_replace($review, ["expires_at" => (string) $review["expires_at"]]),
            array_replace($review, ["expires_at" => null]),
            array_replace($review, ["expected_version" => -1]),
        ];
        $before = $this->snapshot();
        foreach ($cases as $proof) {
            try {
                $f->degrees->publish($f->degree, $this->token($proof));
                self::fail("Malformed publication proof accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testAcceptedOperationCannotBeReusedWithAnotherReasonOrDefinition(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional accepted publication");
        $f->degrees->publish($f->degree, $review["review_token"]);
        $other = $review;
        $other["definition"]["name"] = "A different requirement";
        $cases = [$other, array_replace($review, ["reason" => "A different reason"])];
        $before = $this->snapshot();
        foreach ($cases as $proof) {
            try {
                $f->degrees->publish($f->degree, $this->token($proof));
                self::fail("Accepted publication operation was reused.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testReviewAndConfirmationRecheckPermissionAfterWaitingForLocks(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional waiting publication");
        $transactions = new class ($f) implements UnitOfWork {
            public function __construct(private ReusableGroupFixture $f) {}
            public function run(Closure $operation): mixed
            {
                return $this->f->sis->transactions->run(function () use ($operation): mixed {
                    $this->f->sis->access->staff = false;
                    $this->f->sis->access->grants = [];
                    return $operation();
                });
            }
        };
        $before = $this->snapshot();
        foreach (["review", "publish"] as $method) {
            $f->sis->access->staff = true;
            try {
                $f->degreeService(transactions: $transactions)->$method(
                    $f->degree,
                    $method === "review" ? "Fictional review" : $review["review_token"],
                );
                self::fail("Revoked publication permission ignored.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testPermissionRevokedDuringReplayAlsoBlocksAnAcceptedRetry(): void
    {
        $f = $this->f;
        $review = $f->degrees->review($f->degree, "Fictional replay authorization");
        $f->degrees->publish($f->degree, $review["review_token"]);
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method("page")->willReturnCallback(function ($stream, $cursor, $limit) use ($f) {
            $page = $f->history->page($stream, $cursor, $limit);
            $f->sis->access->staff = false;
            $f->sis->access->grants = [];
            return $page;
        });
        $before = $this->snapshot();
        $this->expectException(AccessDeniedException::class);
        try {
            $f->degreeService(history: $history)->publish($f->degree, $review["review_token"]);
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }
}
