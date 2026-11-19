<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptFulfillmentService;
use Infrastructure\Documents\ProtectedTranscriptRenderer;
use PHPUnit\Framework\TestCase;
use TranscriptFixture;
use TransferAwardFixture;

final class TranscriptAcademicHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
    }

    private function renderer(): TranscriptDocumentRenderer
    {
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional retained academic facts");
        $renderer->method("html")->willReturnCallback(new ProtectedTranscriptRenderer()->html(...));
        return $renderer;
    }

    private function fixture(): array
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $t->postGrade($request);
        $student = new EntityId($t->repository->request($request)["student_id"]);
        $enrollment = $t->sis->sql->rows("sis_enrollments", ["student_id" => $student->value])[0];
        $credit = $t->sis->sql->one("sis_academic_credits", ["source_id" => $enrollment["id"]]);
        return [$t, $request, $student, $enrollment, $credit];
    }

    private function prepare(TranscriptFulfillmentService $service, EntityId $request): EntityId
    {
        $review = $service->review($request);
        $document = new EntityId($review["operation_id"]);
        $service->prepare($request, $document, $review["revision"], true, $review["review_token"]);
        return $document;
    }

    public function testReviewedDatesAndExactAcademicVersionsAreRetainedAndLaterCorrectionsRequireNewPreparation(): void
    {
        [$t, $request, $student, $enrollment, $credit] = $this->fixture();
        $f = $t->sis;
        $section = new EntityId($enrollment["section_id"]);
        $dates = $f
            ->academicDates()
            ->reviewChronology(
                $student,
                $section,
                new EntityId($enrollment["id"]),
                "2026-01-12",
                "2026-05-12",
                1,
                "Fictional protected original source.",
            );
        $f->academicDates()->commitChronology($student, $section, $dates["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        $money = $f->financialEvents();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $row = $review["snapshot"]["credits"][0];
        self::assertSame("2026-01-12", $row["academic_on"]);
        self::assertSame("2026-05-12", $row["completed_on"]);
        self::assertSame(4, $row["academic_version"]);
        self::assertSame(1, $row["registration_version"]);
        self::assertSame("verified", $review["snapshot"]["academic_verification"]["status"]);
        self::assertStringNotContainsString(
            "Fictional protected original source",
            json_encode($review, JSON_THROW_ON_ERROR),
        );
        $document = new EntityId($review["operation_id"]);
        $saved = $service->prepare($request, $document, $review["revision"], true, $review["review_token"]);
        $bytes = $service->pdf($request, $document);
        self::assertStringContainsString("2026-05-12", $service->html($request, $document));
        $delivery = $service->reviewDelivery(
            $request,
            $document,
            1,
            $f->clock->now(),
            "Fictional pending delivery.",
        );
        $later = $f
            ->academicDates()
            ->reviewChronology(
                $student,
                $section,
                new EntityId($enrollment["id"]),
                "2026-02-12",
                "2026-06-12",
                3,
                "Fictional revised source.",
            );
        $f->academicDates()->commitChronology($student, $section, $later["review_token"]);
        $view = $service->view($request, $document);
        self::assertTrue($view["academic_changed"]);
        self::assertSame("verified", $view["academic_check"]);
        self::assertSame("2026-05-12", $view["snapshot"]["credits"][0]["completed_on"]);
        self::assertSame(
            $saved,
            $service->prepare($request, $document, $review["revision"], true, $review["review_token"]),
        );
        try {
            $service->deliver(
                $request,
                $document,
                new EntityId($delivery["operation_id"]),
                1,
                $f->clock->now(),
                "Fictional pending delivery.",
                $delivery["review_token"],
            );
            self::fail("A changed date was released under the earlier document review.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_transcript_deliveries"));
        }
        $replacement = $this->prepare($service, $request);
        self::assertSame(
            "2026-06-12",
            $service->view($request, $replacement)["snapshot"]["credits"][0]["completed_on"],
        );
        self::assertSame($bytes, $service->pdf($request, $document));
        self::assertSame($money, $f->financialEvents());
    }

    public function testProjectionAndJournalDamageBlockNewDocumentsButDoNotHideTheProtectedOriginal(): void
    {
        $cases = ["title", "quantity", "missing_credit", "journal"];
        foreach ($cases as $damage) {
            [$t, $request, $student, $enrollment, $credit] = $this->fixture();
            $f = $t->sis;
            $service = $t->service($this->renderer());
            $document = $this->prepare($service, $request);
            $bytes = $service->pdf($request, $document);
            $delivery = $service->reviewDelivery(
                $request,
                $document,
                1,
                $f->clock->now(),
                "Fictional delivery proof.",
            );
            if ($damage === "journal") {
                $stream = $f->sql->one("sis_history_streams", [
                    "stream_type" => "academic_credit",
                    "aggregate_id" => $credit["id"],
                ]);
                $f->sql->update(
                    "sis_history_events",
                    ["event_hash" => str_repeat("0", 64)],
                    ["stream_id" => $stream["id"]],
                );
            } elseif ($damage === "missing_credit") {
                $f->sql->execute('DELETE FROM "sis_academic_credits" WHERE "id" = ?', [$credit["id"]]);
            } else {
                $values =
                $damage === "title"
                ? ["course_title" => "Unrecorded fictional overwrite"]
                : ["completed_milli" => 1000];
                $f->sql->update("sis_academic_credits", $values, ["id" => $credit["id"]]);
            }
            $events = $f->sql->rows("sis_history_events");
            $documents = $f->sql->rows("sis_transcript_documents");
            try {
                $service->review($request);
                self::fail("Inconsistent academic data was prepared.");
            } catch (ValidationException $error) {
                self::assertSame("academic_history", $error->errors[0]["field"]);
            }
            $view = $service->view($request, $document);
            self::assertSame("unavailable", $view["academic_check"]);
            self::assertNull($view["academic_changed"]);
            self::assertSame($credit["course_title"], $view["snapshot"]["credits"][0]["course_title"]);
            self::assertSame($bytes, $service->pdf($request, $document));
            self::assertStringContainsString($credit["course_title"], $service->html($request, $document));
            try {
                $service->deliver(
                    $request,
                    $document,
                    new EntityId($delivery["operation_id"]),
                    1,
                    $f->clock->now(),
                    "Fictional delivery proof.",
                    $delivery["review_token"],
                );
                self::fail("Inconsistent academic history authorized delivery.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_transcript_deliveries"));
            }
            self::assertSame($documents, $f->sql->rows("sis_transcript_documents"));
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }

    public function testCurrentGradeTableEditsCannotReinterpretAnEarlierOfficialGrade(): void
    {
        [$t, $request, , , $credit] = $this->fixture();
        $service = $t->service($this->renderer());
        $document = $this->prepare($service, $request);
        $t->sis->sql->update(
            "sis_grades",
            ["code" => "EDITED", "points_milli" => 0, "counts_gpa" => 0],
            ["id" => $credit["grade_id"]],
        );
        $review = $service->review($request);
        self::assertSame("A", $review["snapshot"]["credits"][0]["grade_code"]);
        self::assertSame(4000, $review["snapshot"]["totals"]["gpa_milli"]);
        self::assertFalse($service->view($request, $document)["academic_changed"]);
    }

    public function testUnknownWithdrawalGradingRequiresReviewInsteadOfAnInventedGpa(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $student = new EntityId($t->repository->request($request)["student_id"]);
        $enrollment = $t->sis->sql->rows("sis_enrollments", ["student_id" => $student->value])[0];
        $t->sis->clock->time = new \DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $t->sis->service->drop($student, new EntityId($enrollment["section_id"]));
        $events = $t->sis->sql->rows("sis_history_events");
        try {
            $t->service($this->renderer())->review($request);
            self::fail("An unknown withdrawn grade became a complete transcript.");
        } catch (ValidationException $error) {
            self::assertStringContainsString("no verified grading definition", $error->errors[0]["message"]);
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        }
    }

    public function testInventoryLimitsStopBeforeRenderingOrSavingAPartialTranscript(): void
    {
        [$t, $request, $student, , $credit] = $this->fixture();
        for ($i = 0; $i < 200; ++$i) {
            $t->sis->sql->insert(
                "sis_academic_credits",
                array_replace($credit, [
                    "id" => EntityId::generate()->value,
                    "source_id" => EntityId::generate()->value,
                ]),
            );
        }
        $renderer = $this->createMock(TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method("pdf");
        try {
            $t->service($renderer)->review($request);
            self::fail("A limited academic inventory produced a partial transcript.");
        } catch (ValidationException $error) {
            self::assertStringContainsString("verification limit", $error->errors[0]["message"]);
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
        }
    }

    public function testTransferChronologyAndRetainedSourceLabelsShareTheSameVerification(): void
    {
        [$t, $request, $student] = $this->fixture();
        $transfer = new TransferAwardFixture($t->sis, $t->campus->cipher, $student);
        $review = $transfer
            ->periods()
            ->review(
                $student,
                $transfer->award,
                "2026-01-12",
                0,
                "Fictional source transcript dates.",
                "2026-05-12",
                "2026-08-10",
            );
        $transfer->periods()->commit($student, $transfer->award, $review["review_token"]);
        $snapshot = $t->service($this->renderer())->review($request)["snapshot"];
        $row = array_values(
            array_filter(
                $snapshot["credits"],
                static fn(array $row): bool => $row["credit_type"] === "transfer",
            ),
        )[0];
        self::assertSame("2026-01-12", $row["academic_on"]);
        self::assertSame("2026-05-12", $row["completed_on"]);
        self::assertTrue($row["term_label_retained"]);
        self::assertSame("TR", $row["grade_code"]);
        self::assertSame(6000, $snapshot["totals"]["completed_milli"]);
        self::assertSame(4000, $snapshot["totals"]["gpa_milli"]);
    }
    public function testOlderUngradedObservationsAreLabelledWithoutInventingHistoricalEvents(): void
    {
        [$t, $request, $student] = $this->fixture();
        $f = $t->sis;
        $section = $f->section(20, 0);
        $enrollment = EntityId::generate();
        $credit = EntityId::generate();
        $f->sql->insert("sis_enrollments", [
            "id" => $enrollment->value,
            "student_id" => $student->value,
            "section_id" => $section->id->value,
            "term_id" => $section->termId->value,
            "status" => "A",
            "registered_at" => "2026-08-01 12:00:00",
            "status_at" => "2026-08-01 12:00:00",
            "version" => 0,
        ]);
        $f->sql->insert("sis_academic_credits", [
            "id" => $credit->value,
            "source_id" => $enrollment->value,
            "student_id" => $student->value,
            "course_id" => $section->courseId->value,
            "term_id" => $section->termId->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Fictional observed course",
            "attempted_milli" => 3000,
            "completed_milli" => 0,
            "grade_points_milli" => 0,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        $events = $f->sql->rows("sis_history_events");
        $snapshot = $t->service($this->renderer())->review($request)["snapshot"];
        self::assertSame("history_gaps", $snapshot["academic_verification"]["status"]);
        $row = array_values(
            array_filter($snapshot["credits"], static fn(array $row): bool => $row["id"] === $credit->value),
        )[0];
        self::assertFalse($row["history_recorded"]);
        self::assertTrue($row["earlier_history_unknown"]);
        self::assertNull($row["grade_code"]);
        self::assertNull($row["completed_on"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $html = new ProtectedTranscriptRenderer()->html($snapshot + ["preview" => true]);
        self::assertStringContainsString("Current-state observations are identified", $html);
    }

    public function testRemovedEarlyDropHistoryIsVerifiedWithoutExposingRemovedWorkInTheDocument(): void
    {
        [$t, $request, $student] = $this->fixture();
        $f = $t->sis;
        $section = $f->section(20, 0);
        $enrollment = $f->service->register($student, $section->id);
        $removed = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        $f->service->drop($student, $section->id);
        $service = $t->service($this->renderer());
        $snapshot = $service->review($request)["snapshot"];
        self::assertCount(1, $snapshot["credits"]);
        self::assertStringNotContainsString($removed["id"], json_encode($snapshot, JSON_THROW_ON_ERROR));
        $head = $f->sql->one("sis_history_streams", [
            "stream_type" => "academic_credit",
            "aggregate_id" => $removed["id"],
        ]);
        $f->sql->update(
            "sis_history_events",
            ["event_hash" => str_repeat("0", 64)],
            ["stream_id" => $head["id"]],
        );
        $this->expectException(ValidationException::class);
        $service->review($request);
    }
    public function testOriginalDownloadsDoNotReplayCurrentAcademicHistory(): void
    {
        [$t, $request] = $this->fixture();
        $document = $this->prepare($t->service($this->renderer()), $request);
        $records = $this->createStub(\Domain\Transcript\Repository\TranscriptRepository::class);
        $records->method("creditLabels")->willReturnCallback(static function (): never {
            self::fail("An original document download attempted a current academic inventory read.");
        });
        foreach (["document", "request"] as $method) {
            $records->method($method)->willReturnCallback($t->repository->$method(...));
        }
        $service = $t->service($this->renderer(), records: $records);
        self::assertStringStartsWith("%PDF-", $service->pdf($request, $document));
        self::assertStringContainsString("Official academic transcript", $service->html($request, $document));
    }
}
