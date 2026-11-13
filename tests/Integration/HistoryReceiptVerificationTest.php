<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/HistoryVerificationFixture.php";

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use PHPUnit\Framework\TestCase;

final class HistoryReceiptVerificationTest extends TestCase
{
    public function testMaximumSizeReceiptAndSubsequentBatchRecomputeTheirOriginalIntentWithoutWriting(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(54);
        $before = $f->digest();
        $result = $f->verifier->verify();
        self::assertTrue($result->complete);
        self::assertSame(54, $result->events);
        self::assertSame(54, $result->inventoryEvents);
        self::assertSame(2, $result->receipts);
        self::assertSame(54, $result->receiptEvents);
        self::assertSame($before, $f->digest());
    }
    public function testReceiptTraversalHasItsOwnTwentyReceiptCeilingAndResumesWithoutReplaying(): void
    {
        $f = new HistoryVerificationFixture();
        for ($index = 0; $index < 21; $index++) {
            $f->stream(1);
        }
        $before = $f->digest();
        $first = $f->verifier->verify(streamLimit: 50, eventLimit: 2000);
        self::assertFalse($first->complete);
        self::assertSame(21, $first->streams);
        self::assertSame(21, $first->events);
        self::assertSame(21, $first->inventoryEvents);
        self::assertSame(20, $first->receipts);
        self::assertSame(20, $first->receiptEvents);
        $next = $f->verifier->verify($first->nextCursor, eventLimit: 1);
        self::assertTrue($next->complete);
        self::assertSame(0, $next->streams);
        self::assertSame(0, $next->events);
        self::assertSame(0, $next->inventoryEvents);
        self::assertSame(1, $next->receipts);
        self::assertSame(1, $next->receiptEvents);
        self::assertSame($before, $f->digest());
    }
    public function testMissingReceiptFailsReferenceInventoryWithoutInventingOne(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream();
        $f->sis->sql->execute('DELETE FROM "sis_history_operations"');
        $before = $f->digest();
        try {
            $f->verifier->verify();
            self::fail("Events without a retry receipt were accepted.");
        } catch (HistoryVerificationFailedException $e) {
            self::assertNull($e->streamReference);
            self::assertNull($e->getPrevious());
            self::assertSame($before, $f->digest());
        }
    }
    public function testAuthenticatedButIncorrectReceiptContentsAreRejectedWithoutRepair(): void
    {
        foreach (
            [
                "ciphertext",
                "purpose",
                "operation",
                "stream",
                "result_operation",
                "actor",
                "missing_actor",
                "first",
                "last",
                "events",
                "order",
                "fingerprint",
                "oversized",
            ]
            as $kind
        ) {
            $f = new HistoryVerificationFixture();
            $f->stream();
            $row = $f->sis->sql->rows("sis_history_operations", [], 1)[0];
            $saved = $f->codec->open("history.receipt.v1", $row["sealed_receipt"]);
            switch ($kind) {
                case "operation":
                    $saved["operation_id"] = EntityId::generate()->value;
                    break;
                case "stream":
                    $saved["stream_id"] = EntityId::generate()->value;
                    break;
                case "result_operation":
                    $saved["result"]["operation_id"] = EntityId::generate()->value;
                    break;
                case "actor":
                    $saved["actor_id"] = EntityId::generate()->value;
                    break;
                case "missing_actor":
                    unset($saved["actor_id"]);
                    break;
                case "first":
                    $saved["result"]["first_version"] = 0;
                    break;
                case "last":
                    $saved["result"]["last_version"] = 4;
                    break;
                case "events":
                    $saved["result"]["event_ids"][0] = EntityId::generate()->value;
                    break;
                case "order":
                    $saved["result"]["event_ids"] = array_reverse($saved["result"]["event_ids"]);
                    break;
                case "fingerprint":
                    $saved["fingerprint"] = str_repeat("0", 64);
                    break;
                case "oversized":
                    $saved["result"]["last_version"] = 51;
                    $saved["result"]["event_ids"] = array_fill(0, 51, EntityId::generate()->value);
                    break;
            }
            $sealed =
                $kind === "ciphertext"
                    ? "fictional-private-receipt"
                    : $f->codec->seal(
                        $kind === "purpose" ? "history.event.v1" : "history.receipt.v1",
                        $saved,
                    );
            $f->sis->sql->update(
                "sis_history_operations",
                ["sealed_receipt" => $sealed],
                ["id" => $row["id"]],
            );
            $before = $f->digest();
            try {
                $f->verifier->verify();
                self::fail("An invalid " . $kind . " receipt was accepted.");
            } catch (HistoryVerificationFailedException $e) {
                self::assertNull($e->streamReference);
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString("fictional-private-receipt", (string) $e);
                self::assertStringNotContainsString($row["id"], (string) $e);
                self::assertSame($before, $f->digest());
            }
        }
    }
    public function testReceiptCannotBeMovedToAnotherValidStream(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream();
        $first = $f->sis->sql->rows("sis_history_operations", [], 1)[0];
        $other = $f->stream();
        $head = $f->sis->sql->one("sis_history_streams", ["aggregate_id" => (string) $other->aggregateId]);
        $f->sis->sql->update("sis_history_operations", ["stream_id" => $head["id"]], ["id" => $first["id"]]);
        $this->expectException(HistoryVerificationFailedException::class);
        $f->verifier->verify();
    }
    public function testExtraReceiptWithNoEventsOrHeadCannotBeIgnored(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream();
        $row = $f->sis->sql->rows("sis_history_operations", [], 1)[0];
        $saved = $f->codec->open("history.receipt.v1", $row["sealed_receipt"]);
        $row["id"] = EntityId::generate()->value;
        $row["stream_id"] = EntityId::generate()->value;
        $saved["operation_id"] = $saved["result"]["operation_id"] = $row["id"];
        $saved["stream_id"] = $row["stream_id"];
        $row["sealed_receipt"] = $f->codec->seal("history.receipt.v1", $saved);
        $f->sis->sql->execute("PRAGMA foreign_keys = OFF");
        $f->sis->sql->insert("sis_history_operations", $row);
        $before = $f->digest();
        try {
            $f->verifier->verify();
            self::fail("An orphan receipt was ignored.");
        } catch (HistoryVerificationFailedException $e) {
            self::assertNull($e->getPrevious());
            self::assertSame($before, $f->digest());
        }
    }
    public function testReceiptCheckBindsEventsToTheirOriginalOperation(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream();
        $row = $f->sis->sql->rows("sis_history_events", ["position" => 2], 1)[0];
        $receipt = $f->sis->sql->rows("sis_history_operations", [], 1)[0];
        $data = $f->codec->open("history.event.v1", $row["sealed_event"]);
        $data["operation_id"] = EntityId::generate()->value;
        $row["sealed_event"] = $f->codec->seal("history.event.v1", $data);
        unset($row["event_hash"]);
        $row["event_hash"] = hash("sha256", ProtectedEventCodec::canonical($row));
        $f->sis->sql->update("sis_history_events", $row, ["id" => $row["id"]]);
        $before = $f->digest();
        try {
            $f->store->verifyOperation(new EntityId($receipt["id"]));
            self::fail("An event attributed to another operation was accepted.");
        } catch (CorruptHistoryException $e) {
            self::assertNull($e->getPrevious());
            self::assertSame($before, $f->digest());
        }
    }
}
