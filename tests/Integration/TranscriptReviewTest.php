<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptRepository;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use PHPUnit\Framework\TestCase;
use TranscriptFixture;

final class TranscriptReviewTest extends TestCase
{
    private function fixture(): TranscriptFixture
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        return new TranscriptFixture();
    }

    private function renderer(
        ?callable $onPdf = null,
    ): TranscriptDocumentRenderer {
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer
            ->method("pdf")
            ->willReturnCallback(
                $onPdf ??
                    static fn(): string => "%PDF-1.4 Fictional immutable transcript",
            );
        $renderer
            ->method("html")
            ->willReturn("<p>Fictional accessible transcript</p>");
        return $renderer;
    }

    private function prepare(TranscriptFixture $t): array
    {
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review["operation_id"]);
        $saved = $service->prepare(
            $request,
            $id,
            $review["revision"],
            true,
            $review["review_token"],
        );
        return [$request, $id, $review, $saved];
    }

    public function testPreparationProofRejectsMissingTamperedDifferentActorOperationAndRequestBeforeRendering(): void
    {
        $cases1 = ["missing", "tampered", "actor", "operation", "request"];
        foreach ($cases1 as $change) {
            $t = $this->fixture();
            $renderer = $this->createMock(TranscriptDocumentRenderer::class);
            $renderer->expects(self::never())->method("pdf");
            $service = $t->service($renderer);
            $request = $t->request();
            $review = $service->review($request);
            $id = new EntityId($review["operation_id"]);
            $token = $review["review_token"];
            if ($change === "missing") {
                $token = "";
            }
            if ($change === "tampered") {
                $token .= "x";
            }
            if ($change === "actor") {
                $t->sis->access->actor = $t->sis->user();
            }
            if ($change === "operation") {
                $id = EntityId::generate();
            }
            if ($change === "request") {
                $request = $t->request();
            }
            try {
                $service->prepare(
                    $request,
                    $id,
                    $review["revision"],
                    true,
                    $token,
                );
                self::fail("An unrelated preparation review was accepted.");
            } catch (ValidationException | AccessDeniedException) {
                self::assertSame(
                    [],
                    $t->sis->sql->rows("sis_transcript_documents"),
                );
                self::assertSame(
                    "pending",
                    $t->repository->request($request)["status"],
                );
            }
        }
    }

    public function testExpiryBeforeRenderingDuringRenderingAndDuringFinalAuditNeverLeavesAPartialDocument(): void
    {
        $cases2 = ["before", "render", "audit"];
        foreach ($cases2 as $stage) {
            $t = $this->fixture();
            $request = $t->request();
            $expire = static function () use ($t): void {
                $t->sis->clock->time = $t->sis->clock
                    ->now()
                    ->modify("+20 minutes");
            };
            $renderer = $this->renderer(static function () use (
                $stage,
                $expire,
            ): string {
                if ($stage === "render") {
                    $expire();
                }
                return "%PDF-1.4 Fictional rendered bytes";
            });
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $stage,
                    $expire,
                    $t,
                ): void {
                    $t->sis->audit->record($actor, $action, $subject);
                    if (
                        $stage === "audit" &&
                        $action === "transcript.document_prepared"
                    ) {
                        $expire();
                    }
                });
            $service = $t->service($renderer, $audit);
            $review = $service->review($request);
            $events = $t->sis->sql->rows("sis_history_events");
            if ($stage === "before") {
                $expire();
            }
            try {
                $service->prepare(
                    $request,
                    new EntityId($review["operation_id"]),
                    $review["revision"],
                    true,
                    $review["review_token"],
                );
                self::fail("An expired review saved a transcript.");
            } catch (ConcurrentModificationException) {
                self::assertSame(
                    "pending",
                    $t->repository->request($request)["status"],
                );
                self::assertSame(
                    [],
                    $t->sis->sql->rows("sis_transcript_documents"),
                );
                self::assertSame(
                    $events,
                    $t->sis->sql->rows("sis_history_events"),
                );
                self::assertNotContains(
                    "transcript.document_prepared",
                    array_column($t->sis->sql->rows("sis_audit"), "action"),
                );
            }
        }
    }

    public function testChangingTheAuthorizedActorDuringRenderingOrAuditRollsBackTheDocument(): void
    {
        $cases3 = ["render", "audit"];
        foreach ($cases3 as $stage) {
            $t = $this->fixture();
            $request = $t->request();
            $change = static function () use ($t): void {
                $t->sis->access->actor = $t->sis->user();
            };
            $renderer = $this->renderer(static function () use (
                $stage,
                $change,
            ): string {
                if ($stage === "render") {
                    $change();
                }
                return "%PDF-1.4 Fictional prepared bytes";
            });
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $stage,
                    $change,
                ): void {
                    if (
                        $stage === "audit" &&
                        $action === "transcript.document_prepared"
                    ) {
                        $change();
                    }
                });
            $service = $t->service($renderer, $audit);
            $review = $service->review($request);
            try {
                $service->prepare(
                    $request,
                    new EntityId($review["operation_id"]),
                    $review["revision"],
                    true,
                    $review["review_token"],
                );
                self::fail("A different actor completed the preparation.");
            } catch (AccessDeniedException) {
                self::assertNull($t->repository->latest($request));
                self::assertSame(
                    "pending",
                    $t->repository->request($request)["status"],
                );
            }
        }
    }

    public function testRequestOrPersonReassignmentWhileWaitingForLocksInvalidatesTheReview(): void
    {
        $cases4 = ["request", "person"];
        foreach ($cases4 as $change) {
            $t = $this->fixture();
            $request = $t->request();
            $service = $t->service($this->renderer());
            $review = $service->review($request);
            $other = $t->sis->student();
            $otherPerson = $t->sis->legacyPerson(
                $t->sis->user(),
                "Fictional",
                "Reassigned",
            );
            $records = $this->createStub(TranscriptRepository::class);
            $methods = new \ReflectionClass(
                TranscriptRepository::class,
            )->getMethods();
            foreach ($methods as $method) {
                $name = $method->name;
                $records
                    ->method($name)
                    ->willReturnCallback(static function (...$args) use (
                        $name,
                        $t,
                        $request,
                        $other,
                        $otherPerson,
                        $change,
                    ) {
                        $result = $t->repository->$name(...$args);
                        if ($name === "lockRequest") {
                            $student = $t->repository->request($request)[
                                "student_id"
                            ];
                            if ($change === "request") {
                                $t->sis->sql->update(
                                    "sis_transcript_requests",
                                    ["student_id" => $other->id->value],
                                    ["id" => $request->value],
                                );
                            } else {
                                $t->sis->sql->update(
                                    "sis_students",
                                    ["person_id" => $otherPerson->id->value],
                                    ["id" => $student],
                                );
                            }
                        }
                        return $result;
                    });
            }
            try {
                $t->service($this->renderer(), records: $records)->prepare(
                    $request,
                    new EntityId($review["operation_id"]),
                    $review["revision"],
                    true,
                    $review["review_token"],
                );
                self::fail(
                    "A moved request or student identity escaped lock validation.",
                );
            } catch (ConcurrentModificationException) {
                self::assertSame(
                    [],
                    $t->sis->sql->rows("sis_transcript_documents"),
                );
                self::assertSame(
                    "pending",
                    $t->repository->request($request)["status"],
                );
            }
        }
    }

    public function testPreparationAcceptedWhileWaitingForTheRequestLockReturnsItsOriginalResultWithoutRendering(): void
    {
        $t = $this->fixture();
        [$request, $id, $review, $saved] = $this->prepare($t);
        $renderer = $this->createMock(TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method("pdf");
        $records = $this->createStub(TranscriptRepository::class);
        $reads = 0;
        $methods = new \ReflectionClass(TranscriptRepository::class)->getMethods();
        foreach ($methods as $method) {
            $name = $method->name;
            $records
                ->method($name)
                ->willReturnCallback(static function (...$args) use (
                    $name,
                    $t,
                    &$reads,
                ) {
                    if ($name === "document" && ++$reads === 1) {
                        return null;
                    }
                    return $t->repository->$name(...$args);
                });
        }
        self::assertSame(
            $saved,
            $t
                ->service($renderer, records: $records)
                ->prepare(
                    $request,
                    $id,
                    $review["revision"],
                    true,
                    $review["review_token"],
                ),
        );
        self::assertCount(1, $t->sis->sql->rows("sis_transcript_documents"));
    }

    public function testDeliveryBindsEvidenceTimestampOperationAndActorToItsOwnReview(): void
    {
        $cases5 = [
            "missing",
            "evidence",
            "time",
            "operation",
            "actor",
            "purpose",
        ];
        foreach ($cases5 as $change) {
            $t = $this->fixture();
            [$request, $id, $preparation] = $this->prepare($t);
            $service = $t->service($this->renderer());
            $at = $t->sis->clock->now()->modify("-1 minute");
            $review = $service->reviewDelivery(
                $request,
                $id,
                1,
                $at,
                "Fictional pickup evidence",
            );
            $operation = new EntityId($review["operation_id"]);
            $token = $review["review_token"];
            $evidence = "Fictional pickup evidence";
            if ($change === "missing") {
                $token = "";
            }
            if ($change === "evidence") {
                $evidence = "Changed fictional evidence";
            }
            if ($change === "time") {
                $at = $at->modify("-1 minute");
            }
            if ($change === "operation") {
                $operation = EntityId::generate();
            }
            if ($change === "actor") {
                $t->sis->access->actor = $t->sis->user();
            }
            if ($change === "purpose") {
                $token = $preparation["review_token"];
            }
            try {
                $service->deliver(
                    $request,
                    $id,
                    $operation,
                    1,
                    $at,
                    $evidence,
                    $token,
                );
                self::fail("A changed delivery bypassed its review.");
            } catch (ValidationException | AccessDeniedException) {
                self::assertSame(
                    [],
                    $t->sis->sql->rows("sis_transcript_deliveries"),
                );
                self::assertSame(
                    "ready",
                    $t->repository->request($request)["status"],
                );
            }
        }
    }

    public function testDeliveryExpiryAndPermissionLossDuringFinalAuditRollBackEvidenceAndStatus(): void
    {
        $cases6 = ["before", "audit", "permission"];
        foreach ($cases6 as $stage) {
            $t = $this->fixture();
            [$request, $id] = $this->prepare($t);
            $service = $t->service($this->renderer());
            $at = $t->sis->clock->now();
            $review = $service->reviewDelivery(
                $request,
                $id,
                1,
                $at,
                "Fictional handoff evidence",
            );
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $t,
                    $stage,
                ): void {
                    $t->sis->audit->record($actor, $action, $subject);
                    if ($action !== "transcript.delivery_recorded") {
                        return;
                    }
                    if ($stage === "audit") {
                        $t->sis->clock->time = $t->sis->clock
                            ->now()
                            ->modify("+20 minutes");
                    }
                    if ($stage === "permission") {
                        $t->sis->access->staff = false;
                        $t->sis->access->grants = ["sis:transcript:manage"];
                    }
                });
            $document = $t->repository->document($id);
            if ($stage === "before") {
                $t->sis->clock->time = $t->sis->clock
                    ->now()
                    ->modify("+20 minutes");
            }
            try {
                $t->service($this->renderer(), $audit)->deliver(
                    $request,
                    $id,
                    new EntityId($review["operation_id"]),
                    1,
                    $at,
                    "Fictional handoff evidence",
                    $review["review_token"],
                );
                self::fail("A lost delivery review or grant was accepted.");
            } catch (ConcurrentModificationException | AccessDeniedException) {
                self::assertSame(
                    [],
                    $t->sis->sql->rows("sis_transcript_deliveries"),
                );
                self::assertSame(
                    "ready",
                    $t->repository->request($request)["status"],
                );
                self::assertSame($document, $t->repository->document($id));
                self::assertNotContains(
                    "transcript.delivery_recorded",
                    array_column($t->sis->sql->rows("sis_audit"), "action"),
                );
            }
        }
    }

    public function testRecipientChangesRequireAnotherDeliveryReviewWithoutChangingPreparedBytes(): void
    {
        $t = $this->fixture();
        [$request, $id] = $this->prepare($t);
        $service = $t->service($this->renderer());
        $at = $t->sis->clock->now();
        $review = $service->reviewDelivery(
            $request,
            $id,
            1,
            $at,
            "Fictional handoff",
        );
        $document = $t->repository->document($id);
        $t->sis->sql->update(
            "sis_transcript_requests",
            [
                "encrypted_recipient" => $t->campus->cipher->seal(
                    "Changed fictional recipient",
                ),
            ],
            ["id" => $request->value],
        );
        try {
            $service->deliver(
                $request,
                $id,
                new EntityId($review["operation_id"]),
                1,
                $at,
                "Fictional handoff",
                $review["review_token"],
            );
            self::fail(
                "A changed delivery destination reused an earlier review.",
            );
        } catch (ValidationException) {
            self::assertSame($document, $t->repository->document($id));
            self::assertSame(
                [],
                $t->sis->sql->rows("sis_transcript_deliveries"),
            );
        }
        $fresh = $service->reviewDelivery(
            $request,
            $id,
            1,
            $at,
            "Fictional handoff",
        );
        $service->deliver(
            $request,
            $id,
            new EntityId($fresh["operation_id"]),
            1,
            $at,
            "Fictional handoff",
            $fresh["review_token"],
        );
        self::assertSame(
            "fulfilled",
            $t->repository->request($request)["status"],
        );
    }

    public function testOriginalPreparationAndDeliveryRetriesSurviveExpiryLaterRevisionsAndMissingOldProofs(): void
    {
        $t = $this->fixture();
        [$request, $id, $review, $original] = $this->prepare($t);
        $service = $t->service($this->renderer());
        $fresh = $service->review($request);
        $replacement = new EntityId($fresh["operation_id"]);
        $service->prepare(
            $request,
            $replacement,
            $fresh["revision"],
            true,
            $fresh["review_token"],
        );
        $at = $t->sis->clock->now();
        $delivery = $service->reviewDelivery(
            $request,
            $replacement,
            2,
            $at,
            "Fictional delivery",
        );
        $operation = new EntityId($delivery["operation_id"]);
        $result = $service->deliver(
            $request,
            $replacement,
            $operation,
            2,
            $at,
            "Fictional delivery",
            $delivery["review_token"],
        );
        $documents = $t->sis->sql->rows("sis_transcript_documents");
        $evidence = $t->sis->sql->rows("sis_transcript_deliveries");
        $events = $t->sis->sql->rows("sis_history_events");
        $t->sis->clock->time = $t->sis->clock->now()->modify("+1 day");
        self::assertSame(
            $original,
            $service->prepare($request, $id, $review["revision"], true, ""),
        );
        self::assertSame(
            $result,
            $service->deliver(
                $request,
                $replacement,
                $operation,
                2,
                $at,
                "Fictional delivery",
                "",
            ),
        );
        self::assertSame(
            $documents,
            $t->sis->sql->rows("sis_transcript_documents"),
        );
        self::assertSame(
            $evidence,
            $t->sis->sql->rows("sis_transcript_deliveries"),
        );
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame(
            "fulfilled",
            $t->repository->request($request)["status"],
        );
        $t->sis->access->actor = $t->sis->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->prepare($request, $id, $review["revision"], true, "");
    }

    public function testPrivateReadsRecheckTheStaffActorAfterAuditingAndAccessibleRendering(): void
    {
        $cases7 = ["view", "pdf", "html"];
        foreach ($cases7 as $method) {
            $t = $this->fixture();
            [$request, $id] = $this->prepare($t);
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($t): void {
                    $t->sis->access->actor = $t->sis->user();
                });
            try {
                $t->service($this->renderer(), $audit)->$method($request, $id);
                self::fail(
                    "Private transcript content escaped after its actor changed.",
                );
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $t = $this->fixture();
        [$request, $id] = $this->prepare($t);
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer
            ->method("html")
            ->willReturnCallback(static function () use ($t): string {
                $t->sis->access->actor = $t->sis->user();
                return "<p>Fictional</p>";
            });
        $this->expectException(AccessDeniedException::class);
        $t->service($renderer)->html($request, $id);
    }

    public function testSharedProofStillAcceptsTheOriginalHiatusEnvelopeAndSeparatesTranscriptPurposes(): void
    {
        $t = $this->fixture();
        $actor = $t->sis->access->actor;
        $scope = EntityId::generate();
        $operation = EntityId::generate();
        $revision = str_repeat("a", 64);
        $old = $t->campus->cipher->seal(
            json_encode(
                [
                    "kind" => "hiatus.review",
                    "purpose" => "start",
                    "scope" => $scope->value,
                    "actor" => $actor->value,
                    "operation" => $operation->value,
                    "revision" => $revision,
                    "issued_at" => $t->sis->clock->now()->getTimestamp(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $proof = new ReviewProof($t->campus->cipher, $t->sis->clock);
        $proof->validate(
            $old,
            "hiatus.review",
            "start",
            $scope,
            $actor,
            $operation,
            $revision,
        );
        self::assertTrue(true);
        $this->expectException(ValidationException::class);
        $proof->validate(
            $old,
            "transcript.review",
            "prepare",
            $scope,
            $actor,
            $operation,
            $revision,
        );
    }
}
