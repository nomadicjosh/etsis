<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\DegreeAudit\Event\CourseGroupWasPublished;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCourseGroupRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseGroupPublicationReviewTest extends TestCase
{
    private ReusableGroupFixture $f;

    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
        $definition = CourseGroupDefinitionV2::blank("Fictional shared core")->definition;
        $definition["courses"] = $this->f->groupDefinition["courses"];
        $definition["rules"] = $this->f->groupDefinition["rules"];
        $definition["min_credits_milli"] = 12000;
        $this->f->groups->save($this->f->group, 0, new CourseGroupDefinitionV2($definition));
    }

    private function stream(?EntityId $id = null): StreamReference
    {
        return new StreamReference(
            new CourseGroupPublicationId(($id ?? $this->f->group)->value),
            "course_group_publication",
        );
    }

    private function snapshot(): array
    {
        $result = [];
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_course_groups",
            "sis_audit",
        ];
        foreach ($tables as $table) {
            $result[$table] = $this->f->digest($table);
        }
        return $result;
    }

    private function token(array $proof): string
    {
        unset($proof["review_token"], $proof["course_details"]);
        return $this->f->catalog->fixture->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
    }

    public function testPublicationTimeIsCapturedAfterTheConfirmationLockWait(): void
    {
        $f = $this->f;
        $before = $this->snapshot();
        $review = $f->groups->review($f->group, "Fictional timed group approval");
        self::assertSame($before, $this->snapshot());
        self::assertSame("course-group-publication-review", $review["kind"]);
        self::assertSame(2, $review["schema_version"]);
        self::assertArrayNotHasKey("effective_at", $review);
        self::assertSame($f->sis->clock->now()->getTimestamp() + 1200, $review["expires_at"]);
        $transactions = new class ($f) implements UnitOfWork {
            public function __construct(private ReusableGroupFixture $f) {}
            public function run(Closure $operation): mixed
            {
                return $this->f->sis->transactions->run(function () use ($operation): mixed {
                    $this->f->sis->clock->time = $this->f->sis->clock->now()->modify("+3 minutes");
                    return $operation();
                });
            }
        };
        $receipt = $f->groupService(transactions: $transactions)->publish($f->group, $review["review_token"]);
        $stored = $f->history->page($this->stream())->events[1];
        self::assertSame(
            $f->sis->clock->now()->getTimestamp(),
            $stored->context->effectiveAt->getTimestamp(),
        );
        self::assertSame($f->sis->clock->now()->getTimestamp(), $stored->recordedAt->getTimestamp());
        self::assertSame(2, $stored->event->metaParam("schema_version"));
        self::assertSame($receipt["event_ids"][0], (string) $stored->event->eventId());
        self::assertSame("Fictional timed group approval", $stored->context->reason);
    }

    public function testExpiryBoundaryWritesNothingAndRefreshingAllowsPublication(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional expired group approval");
        $f->sis->clock->time = $f->sis->clock->now()->modify("+20 minutes");
        $before = $this->snapshot();
        try {
            $f->groups->publish($f->group, $review["review_token"]);
            self::fail("Expired group review accepted.");
        } catch (ConcurrentModificationException $error) {
            self::assertStringContainsString("expired", $error->getMessage());
            self::assertSame($before, $this->snapshot());
        }
        $fresh = $f->groups->review($f->group, $review["reason"]);
        self::assertNotSame($review["operation_id"], $fresh["operation_id"]);
        self::assertSame(2, $f->groups->publish($f->group, $fresh["review_token"])["last_version"]);
    }

    public function testAcceptedExpiredRetryDoesNotChangeLaterGroupsOrPinnedDegrees(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional original group approval");
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        $f->saveDegree();
        $degree = $f->publishDegree();
        $retained = $f->degrees->version($f->degree, new EntityId($degree["operation_id"]));
        $f->sis->clock->time = $f->sis->clock->now()->modify("+1 day");
        $draft = $f->groups->view($f->group);
        $definition = $draft["definition"];
        $definition["name"] = "Fictional later group";
        $f->groups->save($f->group, $draft["revision"], new CourseGroupDefinitionV2($definition));
        $f->publishGroup();
        $before = $this->snapshot();
        self::assertSame($receipt, $f->groups->publish($f->group, $review["review_token"]));
        self::assertSame($before, $this->snapshot());
        self::assertSame($retained, $f->degrees->version($f->degree, new EntityId($degree["operation_id"])));
        self::assertSame(3, $f->groups->view($f->group)["publication_position"]);
    }

    public static function earlierFormats(): iterable
    {
        yield "earlier credit/average contract" => [1];
        yield "credit-or-count with per-course floor" => [2];
    }

    #[DataProvider("earlierFormats")]
    public function testEarlierAcceptedFormatsKeepTheirTimeAndRetriesButPendingReviewsMustRefresh(
        int $schema,
    ): void {
        $f = $this->f;
        $id = EntityId::generate();
        $definition =
            $schema === 1
                ? new CourseGroupDefinition($f->groupDefinition)
                : new CourseGroupDefinitionV2($f->groups->view($f->group)["definition"]);
        $f->groups->create($id, $definition);
        $review = $f->groups->review($id, "Fictional earlier group approval");
        $review["kind"] = "course-group-publication-review.v" . $schema;
        unset($review["expires_at"], $review["schema_version"]);
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
            $f->groups->publish($id, $token);
            self::fail("An earlier pending group review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot());
        }
        // Record fictional history using the original event shape and review-time context.
        $event = CourseGroupWasPublished::occur(
            $this->stream($id)->aggregateId,
            [
                "version_id" => $review["operation_id"],
                "previous_version_id" => null,
                "definition" => $review["definition"],
            ],
            ["__aggregate_type" => "course_group_publication", "schema_version" => $schema],
        )->withPlayhead(1);
        $receipt = $f->sis->transactions->run(function () use ($f, $id, $review, $context, $event): array {
            $operation = new EntityId($review["operation_id"]);
            $receipt = $f->history->append($this->stream($id), $operation, 0, $context, [$event]);
            new SqlCourseGroupRepository($f->sis->sql)->published(
                $id,
                $operation,
                1,
                $review["definition"]["name"],
            );
            return $receipt;
        });
        $f->sis->clock->time = $f->sis->clock->now()->modify("+1 day");
        $fresh = $f->groups->review($id, "Fictional later group approval");
        $f->groups->publish($id, $fresh["review_token"]);
        $before = $this->snapshot();
        self::assertSame($receipt, $f->groups->publish($id, $token));
        self::assertSame($before, $this->snapshot());
        self::assertSame(
            $review["effective_at"],
            $f->history->page($this->stream($id))->events[0]->context->describe()["effective_at"],
        );
    }

    public function testNewProofsRejectEffectiveTimeMalformedExpiryAndAnotherDefinitionSchema(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional group proof validation");
        $cases = [
            array_replace($review, ["effective_at" => "2000-01-01T00:00:00Z"]),
            array_replace($review, ["expires_at" => (string) $review["expires_at"]]),
            array_replace($review, ["expires_at" => null]),
            array_replace($review, ["expected_version" => -1]),
            array_replace($review, ["schema_version" => 1]),
        ];
        $before = $this->snapshot();
        foreach ($cases as $proof) {
            try {
                $f->groups->publish($f->group, $this->token($proof));
                self::fail("Malformed group proof accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testAcceptedOperationCannotBeReusedForAnotherReasonOrDefinition(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional accepted group");
        $f->groups->publish($f->group, $review["review_token"]);
        $other = $review;
        $other["definition"]["name"] = "A different group";
        $cases = [$other, array_replace($review, ["reason" => "A different reason"])];
        $before = $this->snapshot();
        foreach ($cases as $proof) {
            try {
                $f->groups->publish($f->group, $this->token($proof));
                self::fail("Accepted group operation was reused.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testReviewAndConfirmationRecheckPermissionAfterWaitingForLocks(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional waiting group publication");
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
                $f->groupService(transactions: $transactions)->$method(
                    $f->group,
                    $method === "review" ? "Fictional review" : $review["review_token"],
                );
                self::fail("Revoked group publication permission ignored.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testPermissionRevokedDuringReplayBlocksNewPublicationAndAcceptedRetry(): void
    {
        $f = $this->f;
        $accepted = $f->groups->review($f->group, "Fictional accepted group publication");
        $f->groups->publish($f->group, $accepted["review_token"]);
        $pending = $f->groups->review($f->group, "Fictional pending group publication");
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method("page")->willReturnCallback(function ($stream, $cursor, $limit) use ($f) {
            $page = $f->history->page($stream, $cursor, $limit);
            $f->sis->access->staff = false;
            $f->sis->access->grants = [];
            return $page;
        });
        $before = $this->snapshot();
        foreach ([$accepted, $pending] as $review) {
            $f->sis->access->staff = true;
            try {
                $f->groupService(history: $history)->publish($f->group, $review["review_token"]);
                self::fail("Revoked group permission was ignored during replay.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testProjectionDriftCannotBeUsedForFreshPublication(): void
    {
        $f = $this->f;
        $review = $f->groups->review($f->group, "Fictional inconsistent group projection");
        $f->sis->sql->update("sis_course_groups", ["publication_position" => 0], ["id" => $f->group->value]);
        $before = $this->snapshot();
        $this->expectException(CorruptHistoryException::class);
        try {
            $f->groups->publish($f->group, $review["review_token"]);
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }
}
