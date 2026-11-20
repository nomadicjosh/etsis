<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptReleasePolicyService;
use Domain\Transcript\Service\TranscriptStandingSource;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository;
use PHPUnit\Framework\TestCase;
use TranscriptFixture;

final class TranscriptStandingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
    }

    private function enable(TranscriptFixture $t, bool $enabled = true): void
    {
        $repo = new SqlTranscriptReleasePolicyRepository($t->sis->sql, $t->campus->cipher);
        $service = new TranscriptReleasePolicyService(
            $repo,
            $t->sis->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($t->sis->sql),
            $t->sis->transactions,
            $t->sis->audit,
        );
        $current = $repo->current();
        $service->save($current->revision, $current->checks, $enabled);
    }

    private function fixture(): array
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $student = new EntityId($t->repository->request($request)["student_id"]);
        $pursuit = new EntityId(
            $t->sis->sql->rows("sis_student_programs", ["student_id" => $student->value], 1)[0]["id"],
        );
        $definition = $t->sis
            ->standingService()
            ->saveDefinition(null, "GOOD", "Fictional <b>Good standing</b>", true, null);
        $row = [
            "id" => EntityId::generate()->value,
            "definition_id" => $definition["id"],
            "code" => $definition["code"],
            "title" => $definition["title"],
            "starts_on" => "2026-08-01",
            "ends_on" => null,
            "policy_reference" => "Private fictional office reference",
        ];
        $this->standing($t, $student, $pursuit, [$row]);
        return [$t, $request, $student, $pursuit, $row, $definition];
    }

    private function standing(
        TranscriptFixture $t,
        EntityId $student,
        EntityId $pursuit,
        array $rows,
        bool $closed = false,
    ): void {
        $service = $t->sis->standingService();
        $review = $service->review(
            $student,
            $pursuit,
            $rows,
            $service->view($student, $pursuit)["revision"],
            "Private fictional registrar evidence.",
            $closed,
        );
        $service->commit(
            $student,
            $pursuit,
            new EntityId($review["operation_id"]),
            $review["rows"],
            $review["revision"],
            $review["reason"],
            $review["review_token"],
            $review["historical_evidence"],
        );
    }

    private function renderer(): TranscriptDocumentRenderer
    {
        $real = new \Infrastructure\Documents\ProtectedTranscriptRenderer();
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturnCallback($real->html(...));
        $renderer
            ->method("pdf")
            ->willReturnCallback(
                static fn(array $document): string => "%PDF-1.4 Fictional " .
                    hash("sha256", json_encode($document, JSON_THROW_ON_ERROR)),
            );
        return $renderer;
    }

    private function prepare(
        \Domain\Transcript\Service\TranscriptFulfillmentService $service,
        EntityId $request,
    ): array {
        $review = $service->review($request);
        $document = new EntityId($review["operation_id"]);
        $result = $service->prepare($request, $document, $review["revision"], true, $review["review_token"]);
        return [$document, $review, $result];
    }

    public function testDisabledStandingDoesNotReadTheOptionalSourceOrChangeEarlierDocumentsWhenEnabledLater(): void
    {
        [$t, $request] = $this->fixture();
        $source = $this->createMock(TranscriptStandingSource::class);
        $source->expects(self::never())->method("snapshot");
        $service = $t->service($this->renderer(), standing: $source);
        [$document] = $this->prepare($service, $request);
        self::assertArrayNotHasKey("standing", $service->view($request, $document)["snapshot"]);
        $html = $service->html($request, $document);
        $this->enable($t);
        self::assertFalse($service->view($request, $document)["academic_changed"]);
        self::assertSame($html, $service->html($request, $document));
    }

    public function testOptedInRealPdfAndHtmlRetainOnlyVerifiedApplicableLabelsAndDates(): void
    {
        [$t, $request, $student, $pursuit, $row, $definition] = $this->fixture();
        $row["ends_on"] = "2026-08-10";
        $future = array_replace($row, [
            "id" => EntityId::generate()->value,
            "starts_on" => "2026-08-11",
            "ends_on" => null,
        ]);
        $this->standing($t, $student, $pursuit, [$row, $future]);
        $t->sis
            ->standingService()
            ->saveDefinition(new EntityId($definition["id"]), "GOOD", "Renamed library label", true, 1);
        $this->enable($t);
        $events = $t->sis->sql->rows("sis_history_events");
        $service = $t->service();
        [$document] = $this->prepare($service, $request);
        $view = $service->view($request, $document);
        self::assertCount(1, $view["snapshot"]["standing"]["periods"]);
        self::assertSame(
            "Fictional <b>Good standing</b>",
            $view["snapshot"]["standing"]["periods"][0]["title"],
        );
        self::assertSame($pursuit->value, $view["snapshot"]["standing"]["pursuit_id"]);
        self::assertTrue($view["preparation_release"]["include_standing"]);
        $html = $service->html($request, $document);
        self::assertStringContainsString("Fictional &lt;b&gt;Good standing&lt;/b&gt;", $html);
        self::assertStringNotContainsString("<b>Good standing</b>", $html);
        self::assertStringNotContainsString("Private fictional", $html);
        self::assertStringNotContainsString("2026-08-11", $html);
        self::assertStringStartsWith("%PDF-", $service->pdf($request, $document));
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
    }

    public function testClosedPursuitCorrectionRequiresFreshPreparationAndKeepsOriginalArtifactsAndRetries(): void
    {
        [$t, $request, $student, $pursuit, $row] = $this->fixture();
        $s = $t->sis;
        $s->transactions->run(
            fn() => $s
                ->programStatusLedger()
                ->change(
                    new SqlStudentRecordRepository($s->sql)->assignment($pursuit),
                    "closed",
                    EntityId::generate(),
                    $s->access->actor,
                    "Fictional pursuit closure.",
                ),
        );
        $this->enable($t);
        $service = $t->service($this->renderer());
        [$document, $review, $result] = $this->prepare($service, $request);
        $html = $service->html($request, $document);
        $pdf = $service->pdf($request, $document);
        $row["ends_on"] = "2026-08-09";
        $this->standing($t, $student, $pursuit, [$row], true);
        self::assertTrue($service->view($request, $document)["academic_changed"]);
        self::assertSame($html, $service->html($request, $document));
        self::assertSame($pdf, $service->pdf($request, $document));
        self::assertSame(
            $result,
            $service->prepare($request, $document, $review["revision"], true, $review["review_token"]),
        );
        try {
            $service->reviewDelivery($request, $document, 1, $s->clock->now(), "Fictional pickup.");
            self::fail("Changed printed standing requires a new document.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows("sis_transcript_deliveries"));
        }
        [$updated] = $this->prepare($service, $request);
        self::assertSame(
            "2026-08-09",
            $service->view($request, $updated)["snapshot"]["standing"]["periods"][0]["ends_on"],
        );
        self::assertSame("closed", new SqlStudentRecordRepository($s->sql)->assignment($pursuit)->status);
    }

    public function testRetainedCutoffAndContentChoiceSurviveNewDaysFuturePeriodsAndLaterSetupChanges(): void
    {
        [$t, $request, $student, $pursuit, $row] = $this->fixture();
        $row["ends_on"] = "2026-08-10";
        $this->standing($t, $student, $pursuit, [$row]);
        $this->enable($t);
        $service = $t->service($this->renderer());
        [$document] = $this->prepare($service, $request);
        $html = $service->html($request, $document);
        $future = array_replace($row, [
            "id" => EntityId::generate()->value,
            "starts_on" => "2026-08-11",
            "ends_on" => null,
        ]);
        $this->standing($t, $student, $pursuit, [$row, $future]);
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        $this->enable($t, false);
        $view = $service->view($request, $document);
        self::assertFalse($view["academic_changed"]);
        self::assertSame("2026-08-10", $view["snapshot"]["standing"]["through"]);
        self::assertSame($html, $service->html($request, $document));
        self::assertSame(
            1,
            $service->reviewDelivery(
                $request,
                $document,
                1,
                $t->sis->clock->now()->modify("-1 minute"),
                "Fictional pickup.",
            )["delivery"]["version"],
        );
    }

    public function testProjectionDisagreementStopsPreparationWhileProtectedOriginalsRemainReadable(): void
    {
        [$t, $request, , , $row] = $this->fixture();
        $this->enable($t);
        $service = $t->service($this->renderer());
        [$document] = $this->prepare($service, $request);
        $html = $service->html($request, $document);
        $t->sis->sql->update("sis_student_standing", ["title" => "Unrecorded change"], ["id" => $row["id"]]);
        self::assertSame("unavailable", $service->view($request, $document)["academic_check"]);
        self::assertSame($html, $service->html($request, $document));
        $this->expectException(ValidationException::class);
        $service->review($request);
    }

    public function testStandingChangeDuringFinalTranscriptAuditRollsBackTheDocumentAndNestedDecision(): void
    {
        [$t, $request, $student, $pursuit, $row] = $this->fixture();
        $this->enable($t);
        $row["ends_on"] = "2026-08-09";
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willReturnCallback(function ($actor, string $action) use ($t, $student, $pursuit, $row): void {
                if ($action === "transcript.document_prepared") {
                    $this->standing($t, $student, $pursuit, [$row]);
                }
            });
        $service = $t->service($this->renderer(), $audit);
        $review = $service->review($request);
        $events = $t->sis->sql->rows("sis_history_events");
        try {
            $service->prepare(
                $request,
                new EntityId($review["operation_id"]),
                $review["revision"],
                true,
                $review["review_token"],
            );
            self::fail("The final source check must reject changed printed standing.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            self::assertSame("pending", $t->repository->request($request)["status"]);
        }
    }
}
