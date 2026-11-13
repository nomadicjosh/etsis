<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/HistoryVerificationFixture.php";

use Codefy\Domain\Metadata;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class HistoryVerificationTest extends TestCase
{
    public function testEmptyInventoryIsReadOnlyAndHasNoContinuation(): void
    {
        $f = new HistoryVerificationFixture();
        $before = $f->digest();
        $r = $f->verifier->verify();
        self::assertTrue($r->complete);
        self::assertSame(0, $r->events);
        self::assertSame(0, $r->streams);
        self::assertNull($r->nextCursor);
        self::assertSame($before, $f->digest());
    }
    public function testBoundedContinuationFinishesLongAndMultipleStreamsWithoutWritingAnything(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(45);
        $f->stream(3);
        $before = $f->digest();
        $cursor = null;
        $events = $streams = $calls = 0;
        do {
            $r = $f->verifier->verify(cursor: $cursor, streamLimit: 1, eventLimit: 7);
            self::assertLessThanOrEqual(7, $r->events);
            self::assertLessThanOrEqual(1, $r->streams);
            $events += $r->events;
            $streams += $r->streams;
            $cursor = $r->nextCursor;
            self::assertLessThan(10, ++$calls);
        } while (!$r->complete);
        self::assertSame(48, $events);
        self::assertSame(2, $streams);
        self::assertSame($before, $f->digest());
    }
    public function testContinuationDoesNotDumpFactsAndAnotherPurposeCannotBeUsedAsACursor(): void
    {
        $f = new HistoryVerificationFixture();
        $stream = $f->stream(3);
        $r = $f->verifier->verify(eventLimit: 1);
        self::assertFalse($r->complete);
        self::assertNotNull($r->nextCursor);
        foreach ([$stream->aggregateId->__toString(), "Fictional confidential approval"] as $private) {
            self::assertStringNotContainsString($private, json_encode($r->describe()));
        }
        self::assertStringNotContainsString($r->nextCursor, print_r($r, true));
        self::assertStringNotContainsString($r->nextCursor, json_encode($r));
        $this->expectException(InvalidArgumentException::class);
        $f->verifier->verify($f->codec->seal("history.cursor.v1", ["after" => null, "active" => null]));
    }
    public function testBadCursorIsRejectedWithoutLeakingItsContents(): void
    {
        $f = new HistoryVerificationFixture();
        try {
            $f->verifier->verify("fictional-private-cursor");
            self::fail();
        } catch (InvalidArgumentException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString("fictional-private-cursor", (string) $e);
        }
    }
    public function testModifiedEventOrMissingInteriorRowFailsWithoutRepair(): void
    {
        foreach (["modified", "missing", "head", "unknown_type"] as $kind) {
            $f = new HistoryVerificationFixture();
            $f->stream(3);
            $row = $f->sis->sql->rows("sis_history_events", ["position" => 2], 1)[0];
            if ($kind === "modified") {
                $f->sis->sql->update(
                    "sis_history_events",
                    ["sealed_event" => "fictional-confidential-body"],
                    ["id" => $row["id"]],
                );
            } elseif ($kind === "missing") {
                $f->sis->sql->execute('DELETE FROM "sis_history_events" WHERE "id" = ?', [$row["id"]]);
            } elseif ($kind === "head") {
                $f->sis->sql->update(
                    "sis_history_streams",
                    ["sealed_anchor" => "fictional-confidential-body"],
                    ["id" => $row["stream_id"]],
                );
            } else {
                $f->sis->sql->update(
                    "sis_history_streams",
                    ["stream_type" => "unregistered-type"],
                    ["id" => $row["stream_id"]],
                );
            }
            $before = $f->digest();
            try {
                $f->verifier->verify();
                self::fail("Broken history was accepted.");
            } catch (HistoryVerificationFailedException $e) {
                self::assertSame($row["stream_id"], $e->streamReference->value);
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString("fictional-confidential-body", (string) $e);
                self::assertSame($before, $f->digest());
            }
        }
    }
    public function testRowsOutsideTheCommittedHeadAreNotSilentlyIgnored(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(2);
        $row = $f->sis->sql->rows("sis_history_events", ["position" => 2], 1)[0];
        $row["id"] = EntityId::generate()->value;
        $row["event_id"] = EntityId::generate()->value;
        $row["position"] = 3;
        $f->sis->sql->insert("sis_history_events", $row);
        $this->expectException(HistoryVerificationFailedException::class);
        $f->verifier->verify();
    }
    public function testFrozenCurrentStreamCanFinishWhileNewEventsAreAppended(): void
    {
        $f = new HistoryVerificationFixture();
        $stream = $f->stream(3);
        $r = $f->verifier->verify(eventLimit: 1);
        $context = new \Domain\Shared\EventSourcing\EventContext(
            $f->sis->access->actor,
            $f->sis->clock->now(),
            EntityId::generate(),
        );
        $event = \Domain\Finance\Event\AccountEntryWasPosted::occur(
            $stream->aggregateId,
            [
                "operation_id" => EntityId::generate()->value,
                "type" => "charge",
                "minor" => 100,
                "currency" => "USD",
                "actor_id" => $context->actor->value,
            ],
            [Metadata::AGGREGATE_TYPE => "student_account"],
        )->withPlayhead(4);
        $f->store->append($stream, EntityId::generate(), 3, $context, [$event]);
        $next = $f->verifier->verify($r->nextCursor);
        self::assertTrue($next->complete);
        self::assertSame(2, $next->events);
        self::assertSame(4, $f->verifier->verify()->events);
    }
    public function testFrameworkUserAndFinancialFactsShareTheVerificationWithoutLeakingCredentials(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(2);
        $id = new \Domain\User\ValueObject\UserId();
        $event = \Domain\User\Event\UserWasCreated::withData(
            $id,
            new \Domain\User\ValueObject\Username("fictional"),
            new \Domain\User\ValueObject\UserToken(),
            \Qubus\ValueObjects\Person\Name::fromNative("Private", "", "Learner"),
            new \Qubus\ValueObjects\Web\EmailAddress("private@example.invalid"),
            new \Domain\User\ValueObject\UserRole("student"),
            new \Qubus\ValueObjects\StringLiteral\StringLiteral("fictional-private-password-hash"),
            $f->sis->clock->now(),
        );
        $f->store->append(
            new \Domain\Shared\EventSourcing\StreamReference($id, "user"),
            EntityId::generate(),
            0,
            new \Domain\Shared\EventSourcing\EventContext(
                $f->sis->access->actor,
                $f->sis->clock->now(),
                EntityId::generate(),
            ),
            [$event],
        );
        $before = $f->digest();
        $result = $f->verifier->verify();
        self::assertSame(2, $result->streams);
        self::assertSame(3, $result->events);
        self::assertStringNotContainsString("private@example.invalid", json_encode($result->describe()));
        self::assertStringNotContainsString(
            "fictional-private-password-hash",
            json_encode($result->describe()),
        );
        self::assertSame($before, $f->digest());
    }
    public function testLegacyStorageCannotBeMistakenForAnEmptyProtectedJournal(): void
    {
        $f = new HistoryVerificationFixture();
        $f->sis->sql->execute('CREATE TABLE "event_store" ("id" VARCHAR(26))');
        $this->expectException(LogicException::class);
        $f->verifier->verify();
    }
    public function testRecordedGradingScalesUseTheSameAllowlistedStreamLookup(): void
    {
        $f = new HistoryVerificationFixture();
        $id = new \Domain\Gradebook\ValueObject\GradingScaleId(
            \Domain\Gradebook\Service\GradingScaleService::GLOBAL_ID,
        );
        $schemas = new \Infrastructure\EventSourcing\HistoricalSchemaRegistry();
        $stream = $schemas->stream("grading_scale", (string) $id);
        self::assertSame("grading_scale", $schemas->streamFor($id)->type);
        self::assertSame((string) $id, (string) $stream->aggregateId);
        $version = EntityId::generate();
        $event = \Domain\Gradebook\Event\GradingScaleWasChanged::occur(
            $id,
            [
                "version_id" => $version->value,
                "previous_version_id" => null,
                "definition" => [
                    "grades" => [
                        [
                            "code" => "A",
                            "min_score_milli" => 90000,
                            "max_score_milli" => 100000,
                            "points_milli" => 4000,
                            "passes" => true,
                            "counts_gpa" => true,
                        ],
                    ],
                ],
            ],
            [Metadata::AGGREGATE_TYPE => "grading_scale"],
        )->withPlayhead(1);
        $f->store->append(
            $stream,
            $version,
            0,
            new \Domain\Shared\EventSourcing\EventContext(
                $f->sis->access->actor,
                $f->sis->clock->now(),
                $version,
                reason: "Fictional grading policy approval",
            ),
            [$event],
        );
        $before = $f->digest();
        $result = $f->verifier->verify();
        self::assertTrue($result->complete);
        self::assertSame(1, $result->streams);
        self::assertSame(1, $result->events);
        self::assertSame($before, $f->digest());
    }
}
