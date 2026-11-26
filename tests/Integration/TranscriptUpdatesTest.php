<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptUpdatesService;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptUpdatesRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TranscriptFixture;

final class TranscriptUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
    }

    private function renderer(): TranscriptDocumentRenderer
    {
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturn("<p>Fictional protected transcript</p>");
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional protected transcript");
        return $renderer;
    }

    private function updates(TranscriptFixture $t, ?AuditTrail $audit = null): TranscriptUpdatesService
    {
        $f = $t->sis;
        return new TranscriptUpdatesService(
            new SqlTranscriptUpdatesRepository($f->sql),
            $t->repository,
            new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator()),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }

    private function prepare(TranscriptFixture $t, EntityId $request): array
    {
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $document = new EntityId($review["operation_id"]);
        $receipt = $service->prepare($request, $document, $review["revision"], true, $review["review_token"]);
        return [$review, $document, $receipt];
    }

    private function owner(TranscriptFixture $t, EntityId $request): EntityId
    {
        return new EntityId($t->repository->request($request)["owner_user_id"]);
    }

    public function testReviewAndPreviewStaySilentAndPreparationPublishesOnlyMinimalOwnerStatus(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $service->preview(
            $request,
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["review_token"],
        );
        self::assertSame([], $f->sql->rows("sis_transcript_updates"));
        [, $document] = $this->prepare($t, $request);
        $row = $f->sql->rows("sis_transcript_updates")[0];
        self::assertSame(1, (int) $row["revision"]);
        self::assertSame("ready", $row["status"]);
        self::assertSame($document->value, $row["document_id"]);
        $f->access->actor = $this->owner($t, $request);
        $f->access->staff = false;
        $items = $this->updates($t)->page()["items"];
        self::assertCount(1, $items);
        self::assertEqualsCanonicalizing(
            ["id", "revision", "status", "recorded_at", "requested_at", "delivery", "unread"],
            array_keys($items[0]),
        );
        $detail = $this->updates($t)->detail(new EntityId($row["id"]));
        self::assertSame(["requested_at", "delivery", "status", "recorded_at"], array_keys($detail));
        self::assertSame("ready", $detail["status"]);
        self::assertStringNotContainsString(
            "Fictional protected transcript",
            json_encode([$row, $items, $detail]),
        );
        $privateFields = [
            "recipient", "evidence", "document_id", "student_id", "person_id", "pdf", "encrypted_content",
        ];
        foreach ($privateFields as $private) {
            self::assertArrayNotHasKey($private, $items[0]);
            self::assertArrayNotHasKey($private, $detail);
        }
    }

    public function testReadAndPhysicalDeletionLeaveDocumentsAndAcceptedExpiredRetriesIntact(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        [$review, $document, $receipt] = $this->prepare($t, $request);
        $staff = $f->access->actor;
        $row = $f->sql->rows("sis_transcript_updates")[0];
        $before = [
            $f->sql->rows("sis_transcript_documents"),
            $f->sql->rows("sis_transcript_requests"),
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_history_operations"),
        ];
        $f->access->actor = $this->owner($t, $request);
        $f->access->staff = false;
        $updates = $this->updates($t);
        $id = new EntityId($row["id"]);
        $updates->read($id, 1);
        $readAt = $f->sql->rows("sis_transcript_updates")[0]["read_at"];
        $f->clock->time = $f->clock->time->modify("+1 day");
        $updates->read($id, 1);
        self::assertSame($readAt, $f->sql->rows("sis_transcript_updates")[0]["read_at"]);
        self::assertSame([], $updates->page()["items"]);
        self::assertCount(1, $updates->page(false)["items"]);
        self::assertSame(["deleted" => true], $updates->delete($id, 1));
        $count = count($f->audit->forSubject($id));
        $updates->delete($id, 1);
        self::assertSame($count, count($f->audit->forSubject($id)));
        self::assertSame([], $f->sql->rows("sis_transcript_updates"));
        $f->access->actor = $staff;
        $f->access->staff = true;
        self::assertSame(
            $receipt,
            $t
                ->service($this->renderer())
                ->prepare($request, $document, $review["revision"], true, $review["review_token"]),
        );
        self::assertSame([], $f->sql->rows("sis_transcript_updates"));
        self::assertSame($before, [
            $f->sql->rows("sis_transcript_documents"),
            $f->sql->rows("sis_transcript_requests"),
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_history_operations"),
        ]);
    }

    public function testLaterPreparationAndDeliveryReplaceTheNoticeAndOldRetriesCannotResurrectIt(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        [$oldReview, $oldDocument, $oldReceipt] = $this->prepare($t, $request);
        $old = $f->sql->rows("sis_transcript_updates")[0];
        [, $document] = $this->prepare($t, $request);
        $new = $f->sql->rows("sis_transcript_updates")[0];
        self::assertNotSame($old["id"], $new["id"]);
        self::assertSame(2, (int) $new["revision"]);
        self::assertSame(
            $oldReceipt,
            $t
                ->service($this->renderer())
                ->prepare($request, $oldDocument, $oldReview["revision"], true, $oldReview["review_token"]),
        );
        self::assertSame([$new], $f->sql->rows("sis_transcript_updates"));
        $service = $t->service($this->renderer());
        $on = $f->clock->now()->modify("-1 minute");
        $review = $service->reviewDelivery($request, $document, 2, $on, "Fictional private pickup receipt");
        $operation = new EntityId($review["operation_id"]);
        $receipt = $service->deliver(
            $request,
            $document,
            $operation,
            2,
            $on,
            "Fictional private pickup receipt",
            $review["review_token"],
        );
        $delivered = $f->sql->rows("sis_transcript_updates")[0];
        self::assertSame("fulfilled", $delivered["status"]);
        self::assertSame(3, (int) $delivered["revision"]);
        self::assertCount(1, $f->sql->rows("sis_transcript_updates"));
        $staff = $f->access->actor;
        $f->access->actor = $this->owner($t, $request);
        $f->access->staff = false;
        $updates = $this->updates($t);
        $updates->delete(new EntityId($old["id"]), 1);
        self::assertSame([$delivered], $f->sql->rows("sis_transcript_updates"));
        self::assertSame("fulfilled", $updates->detail(new EntityId($delivered["id"]))["status"]);
        $updates->delete(new EntityId($delivered["id"]), 3);
        $f->access->actor = $staff;
        $f->access->staff = true;
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame(
            $receipt,
            $service->deliver(
                $request,
                $document,
                $operation,
                2,
                $on,
                "Fictional private pickup receipt",
                $review["review_token"],
            ),
        );
        self::assertSame([], $f->sql->rows("sis_transcript_updates"));
        self::assertCount(2, $f->sql->rows("sis_transcript_documents"));
        self::assertCount(1, $f->sql->rows("sis_transcript_deliveries"));
    }

    public function testEvenTranscriptStaffCannotReadOrDeleteAnotherAccountsNotice(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $this->prepare($t, $request);
        $row = $f->sql->rows("sis_transcript_updates")[0];
        // A different staff person has the same duties, without the original account's ownership.
        $foreign = $f->user();
        $f->student($foreign);
        $f->access->actor = $foreign;
        $updates = $this->updates($t);
        self::assertSame([], $updates->page(false)["items"]);
        try {
            $updates->detail(new EntityId($row["id"]));
            self::fail("A foreign notice was readable.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        self::assertSame(["deleted" => true], $updates->delete(new EntityId($row["id"]), 1));
        self::assertSame([$row], $f->sql->rows("sis_transcript_updates"));
    }

    public function testRequiredAuditSourceRecipientNoticeAndAuthorityFailuresRollbackPreparation(): void
    {
        foreach (["audit", "source", "owner", "notice", "authority"] as $failure) {
            $t = new TranscriptFixture();
            $f = $t->sis;
            $request = $t->request();
            $replacement = $f->user();
            $review = $t->service($this->renderer())->review($request);
            $before = [
                $f->sql->rows("sis_persons"),
                $f->sql->rows("sis_transcript_requests"),
                $f->sql->rows("sis_history_events"),
            ];
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(function ($actor, $action) use ($f, $request, $failure, $replacement): void {
                    if ($action !== "transcript.document_prepared") {
                        return;
                    }
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional required audit failure");
                    }
                    if ($failure === "source") {
                        $f->sql->update(
                            "sis_transcript_requests",
                            ["delivery" => "electronic"],
                            ["id" => $request->value],
                        );
                    }
                    if ($failure === "notice") {
                        $f->sql->execute("DELETE FROM sis_transcript_updates");
                    }
                    if ($failure === "authority") {
                        $f->access->staff = false;
                    }
                    if ($failure === "owner") {
                        $student = $f->admissions->student(
                            new EntityId(
                                $f->sql->one("sis_transcript_requests", ["id" => $request->value])["student_id"],
                            ),
                        );
                        $f->sql->update(
                            "sis_persons",
                            ["owner_user_id" => $replacement->value],
                            ["id" => $student->personId->value],
                        );
                    }
                });
            try {
                $t->service($this->renderer(), $audit)->prepare(
                    $request,
                    new EntityId($review["operation_id"]),
                    $review["revision"],
                    true,
                    $review["review_token"],
                );
                self::fail("A failed transcript confirmation committed.");
            } catch (RuntimeException) {
                self::assertSame([], $f->sql->rows("sis_transcript_updates"));
                self::assertSame([], $f->sql->rows("sis_transcript_documents"));
                self::assertSame($before, [
                    $f->sql->rows("sis_persons"),
                    $f->sql->rows("sis_transcript_requests"),
                    $f->sql->rows("sis_history_events"),
                ]);
            }
        }
    }

    public function testFailedDeliveryPreservesTheEarlierNoticeAndPrivateDocument(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        [, $document] = $this->prepare($t, $request);
        $before = [
            $f->sql->rows("sis_transcript_updates"),
            $f->sql->rows("sis_transcript_documents"),
            $f->sql->rows("sis_transcript_requests"),
        ];
        $on = $f->clock->now()->modify("-1 minute");
        $review = $t
            ->service($this->renderer())
            ->reviewDelivery($request, $document, 1, $on, "Fictional private receipt");
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional required audit failure"));
        try {
            $t->service($this->renderer(), $audit)->deliver(
                $request,
                $document,
                new EntityId($review["operation_id"]),
                1,
                $on,
                "Fictional private receipt",
                $review["review_token"],
            );
            self::fail("A failed delivery committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_transcript_deliveries"));
            self::assertSame($before, [
                $f->sql->rows("sis_transcript_updates"),
                $f->sql->rows("sis_transcript_documents"),
                $f->sql->rows("sis_transcript_requests"),
            ]);
        }
    }

    public function testNoticeMutationsRequireCurrentRevisionAuditAndStableSource(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $this->prepare($t, $request);
        $row = $f->sql->rows("sis_transcript_updates")[0];
        $replacement = $f->user();
        $f->access->actor = $this->owner($t, $request);
        $f->access->staff = false;
        foreach (["read", "delete"] as $method) {
            try {
                $this->updates($t)->{$method}(new EntityId($row["id"]), 0);
                self::fail("A stale notice was changed.");
            } catch (ConcurrentModificationException) {
                self::assertSame([$row], $f->sql->rows("sis_transcript_updates"));
            }
            foreach (["audit", "source", "notice", "owner"] as $failure) {
                $audit = $this->createStub(AuditTrail::class);
                $audit
                    ->method("record")
                    ->willReturnCallback(function () use (
                        $failure,
                        $f,
                        $request,
                        $method,
                        $row,
                        $replacement,
                    ): void {
                        if ($failure === "audit") {
                            throw new RuntimeException("Fictional required audit failure");
                        }
                        if ($failure === "source") {
                            $f->sql->update(
                                "sis_transcript_requests",
                                ["delivery" => "electronic"],
                                ["id" => $request->value],
                            );
                        }
                        if ($failure === "notice") {
                            if ($method === "delete") {
                                $f->sql->insert("sis_transcript_updates", $row);
                            } else {
                                $f->sql->execute("DELETE FROM sis_transcript_updates");
                            }
                        }
                        if ($failure === "owner") {
                            $f->sql->update(
                                "sis_persons",
                                ["owner_user_id" => $replacement->value],
                                ["id" => $row["person_id"]],
                            );
                        }
                    });
                try {
                    $this->updates($t, $audit)->{$method}(new EntityId($row["id"]), 1);
                    self::fail("A failed notice mutation committed.");
                } catch (RuntimeException) {
                    self::assertSame([$row], $f->sql->rows("sis_transcript_updates"));
                }
            }
        }
    }

    public function testReplacementAccountsCannotInheritEarlierRequestNotices(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        [, $document] = $this->prepare($t, $request);
        $rows = $f->sql->rows("sis_transcript_updates");
        $replacement = $f->user();
        $f->sql->update("sis_persons", ["owner_user_id" => $replacement->value], ["id" => $rows[0]["person_id"]]);
        $f->access->actor = $replacement;
        $f->access->staff = false;
        self::assertSame([], $this->updates($t)->page(false)["items"]);
        $publisher = new \Domain\Transcript\Service\TranscriptNotifications(
            new SqlTranscriptUpdatesRepository($f->sql),
            $t->repository,
            $f->admissions,
            $f->clock,
        );
        $f->transactions->run(function () use ($publisher, $request, $document): void {
            $context = $publisher->record($request, $document, EntityId::generate(), "ready");
            self::assertNull($context["reference"]);
            $publisher->verify($context);
        });
        self::assertSame($rows, $f->sql->rows("sis_transcript_updates"));
        $this->expectException(AccessDeniedException::class);
        $this->updates($t)->detail(new EntityId($rows[0]["id"]));
    }

    public function testPagesUseCompleteTimestampAndIdCursorsAndKeepReadNoticesUntilDeleted(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $record = $t->repository->request($request);
        $staff = $f->access->actor;
        for ($index = 0; $index < 21; $index++) {
            if ($index > 0) {
                $request = EntityId::generate();
                $f->access->actor = new EntityId($record["owner_user_id"]);
                $t->campus->service->requestTranscript(
                    $request,
                    new EntityId($record["student_id"]),
                    "pickup",
                    "",
                );
                $f->access->actor = $staff;
            }
            $this->prepare($t, $request);
        }
        $f->access->actor = new EntityId($record["owner_user_id"]);
        $f->access->staff = false;
        $updates = $this->updates($t);
        $first = $updates->page(false);
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next"]);
        $second = $updates->page(false, $first["next"]["at"], new EntityId($first["next"]["id"]));
        self::assertCount(1, $second["items"]);
        self::assertNull($second["next"]);
        self::assertCount(21, array_unique(array_column([...$first["items"], ...$second["items"]], "id")));
        $this->expectException(\InvalidArgumentException::class);
        $updates->page(false, $first["next"]["at"]);
    }
}
