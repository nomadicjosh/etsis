<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/TranscriptFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\ValueObject\TranscriptIssuer;
use PHPUnit\Framework\TestCase;

final class TranscriptFulfillmentTest extends TestCase
{
    private function renderer(): TranscriptDocumentRenderer
    {
        $stub = $this->createStub(TranscriptDocumentRenderer::class);
        $stub->method('pdf')->willReturn('%PDF-1.4 Fictional protected transcript');
        return $stub;
    }

    public function testRealPdfPreparationEncryptsTheImmutableDocumentAndDeliveryRequiresSeparateEvidence(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $service = $t->service();
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        self::assertSame('pending', $t->repository->request($request)['status']);
        $saved = $service->prepare($request, $id, $review['revision'], true);
        self::assertSame($saved, $service->prepare($request, $id, $review['revision'], true));
        self::assertSame('ready', $t->repository->request($request)['status']);
        $pdf = $service->pdf($request, $id);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame($saved['pdf_hash'], hash('sha256', $pdf));
        self::assertSame($pdf, $service->pdf($request, $id));
        $raw = $t->repository->document($id)['encrypted_content'];
        self::assertStringNotContainsString('Fictional University', $raw);
        self::assertStringNotContainsString('%PDF-', $raw);
        self::assertSame([], $f->sql->rows('sis_transcript_deliveries'));
        $operation = EntityId::generate();
        $at = $f->clock->now()->modify('-1 minute');
        $delivered = $service->deliver($request, $id, $operation, 1, $at, 'Fictional pickup receipt 123');
        self::assertSame($delivered, $service->deliver($request, $id, $operation, 1, $at, 'Fictional pickup receipt 123'));
        self::assertSame('fulfilled', $t->repository->request($request)['status']);
        self::assertSame('Fictional pickup receipt 123', $service->view($request, $id)['delivery']['evidence']);
        self::assertStringNotContainsString('Fictional pickup receipt', json_encode($f->sql->rows('sis_transcript_deliveries')));
        self::assertStringNotContainsString('Fictional pickup receipt', json_encode($f->sql->rows('sis_audit')));
    }

    public function testAcademicChangeInvalidatesPreparationAndDeliveryUntilAnUpdatedRevisionIsPrepared(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        $service->prepare($request, $id, $review['revision'], true);
        $originalPdf = $service->pdf($request, $id);
        $student = $t->repository->request($request)['student_id'];
        $f->sql->update('sis_academic_credits', ['course_title' => 'Fictional corrected course', 'version' => 1], ['student_id' => $student]);
        self::assertTrue($service->view($request, $id)['academic_changed']);
        try {
            $service->deliver($request, $id, EntityId::generate(), 1, $f->clock->now(), 'Fictional stale delivery');
            self::fail('A stale document was delivered.');
        } catch (ConcurrentModificationException) {
            self::assertSame('ready', $t->repository->request($request)['status']);
        }
        $fresh = $service->review($request);
        $replacement = new EntityId($fresh['operation_id']);
        $service->prepare($request, $replacement, $fresh['revision'], true);
        self::assertFalse($service->view($request, $id)['is_latest']);
        self::assertSame($originalPdf, $service->pdf($request, $id));
        self::assertSame('Fictional corrected course', $service->view($request, $replacement)['snapshot']['credits'][0]['course_title']);
        self::assertCount(2, $f->sql->rows('sis_transcript_documents'));
        $service->deliver($request, $replacement, EntityId::generate(), 2, $f->clock->now(), 'Fictional corrected delivery');
        self::assertSame('fulfilled', $t->repository->request($request)['status']);
    }

    public function testAChangeDuringPdfRenderingRejectsThePreparedBytesWithoutSavingAPartialDocument(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method('pdf')->willReturnCallback(static function (array $snapshot) use ($t, $request): string {
            $t->sis->sql->update('sis_academic_credits', ['version' => 1], ['student_id' => $t->repository->request($request)['student_id']]);
            return '%PDF-1.4 Fictional changed during render';
        });
        $service = $t->service($renderer);
        $review = $service->review($request);
        try {
            $service->prepare($request, new EntityId($review['operation_id']), $review['revision'], true);
            self::fail('An unreviewed academic change was saved into a prepared request.');
        } catch (ConcurrentModificationException) {
            self::assertSame('pending', $t->repository->request($request)['status']);
            self::assertSame([], $f->sql->rows('sis_transcript_documents'));
        }
    }

    public function testStaffMustHaveBothTranscriptAndAcademicReadGrantsAndCannotMoveDocumentsBetweenRequests(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        $service->prepare($request, $id, $review['revision'], true);
        $other = $t->request();
        try {
            $service->pdf($other, $id);
            self::fail('A document moved to another request.');
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $f->access->staff = false;
        foreach ([[], ['sis:student:read'], ['sis:transcript:manage']] as $grants) {
            $f->access->grants = $grants;
            foreach (['review', 'pdf', 'view', 'current'] as $method) {
                try {
                    match ($method) {
                        'review' => $service->review($request), 'pdf' => $service->pdf($request, $id),
                        'view' => $service->view($request, $id), 'current' => $service->current($request),
                    };
                    self::fail('A student owner or insufficient office grant accessed an official document.');
                } catch (AccessDeniedException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testInstitutionalIssuerAndExplicitReleaseReviewAreRequired(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service($this->renderer(), issuer: new TranscriptIssuer());
        $review = $service->review($request);
        foreach ([false, true] as $confirmed) {
            try {
                $service->prepare($request, new EntityId($review['operation_id']), $review['revision'], $confirmed);
                self::fail('An unconfigured or unreviewed official document was issued.');
            } catch (ValidationException) {
                self::assertNull($t->repository->latest($request));
            }
        }
    }

    public function testTranscriptGpaExcludesInProgressAndWithdrawnCreditsAndOmitsDroppedCourses(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $student = new EntityId($t->repository->request($request)['student_id']);
        $enrollment = $f->sql->rows('sis_enrollments', ['student_id' => $student->value])[0];
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), 'A', 4000, true, true);
        $t->campus->sis->legacyGrade($grade);
        $t->campus->gradebook->finalGrade($student, new EntityId($enrollment['section_id']), $grade->id, 0);
        $progress = $f->section(20, 0);
        $withdrawn = $f->section(20, 0);
        $dropped = $f->section(20, 0);
        foreach ([$progress, $withdrawn, $dropped] as $section) {
            $f->service->register($student, $section->id);
        }
        $f->service->drop($student, $dropped->id);
        $f->clock->time = new DateTimeImmutable('2026-08-20T10:00:00-07:00');
        $f->service->drop($student, $withdrawn->id);
        $review = $t->service($this->renderer())->review($request)['snapshot'];
        self::assertCount(3, $review['credits']);
        self::assertSame(['attempted_milli' => 9000, 'completed_milli' => 3000, 'gpa_milli' => 4000], $review['totals']);
        self::assertContains('W', array_column($review['credits'], 'grade_code'));
    }

    public function testOversizedAcademicRecordsFailBeforeRenderingInsteadOfProducingPartialTranscripts(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $request = $t->request();
        $student = $t->repository->request($request)['student_id'];
        $credit = $f->sql->rows('sis_academic_credits', ['student_id' => $student])[0];
        for ($i = 0; $i < 500; ++$i) {
            $f->sql->insert('sis_academic_credits', array_replace(
                $credit,
                ['id' => EntityId::generate()->value, 'source_id' => EntityId::generate()->value]
            ));
        }
        $renderer = $this->createMock(TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method('pdf');
        $this->expectException(ValidationException::class);
        $t->service($renderer)->review($request);
    }

    public function testDeliveryAuditFailureRollsBackEvidenceAndFulfillmentWhileKeepingPreparedBytes(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        $service->prepare($request, $id, $review['revision'], true);
        $bytes = $service->pdf($request, $id);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $subject): void {
            if ($action === 'transcript.delivery_recorded') {
                throw new RuntimeException('Fictional delivery audit failure');
            }
        });
        try {
            $t->service($this->renderer(), $audit)->deliver(
                $request,
                $id,
                EntityId::generate(),
                1,
                $t->sis->clock->now(),
                'Fictional failed collection'
            );
            self::fail('Partially audited delivery was marked fulfilled.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional delivery audit failure', $error->getMessage());
        }
        self::assertSame('ready', $t->repository->request($request)['status']);
        self::assertSame([], $t->sis->sql->rows('sis_transcript_deliveries'));
        self::assertSame($bytes, $service->pdf($request, $id));
    }

    public function testLateAuditFailureRollsBackPreparedDocumentAndReadyStatus(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $subject): void {
            if ($action === 'transcript.document_prepared') {
                throw new RuntimeException('Fictional document audit failure');
            }
        });
        $service = $t->service($this->renderer(), $audit);
        $review = $service->review($request);
        try {
            $service->prepare($request, new EntityId($review['operation_id']), $review['revision'], true);
            self::fail('A partially audited document was issued.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional document audit failure', $error->getMessage());
        }
        self::assertNull($t->repository->latest($request));
        self::assertSame('pending', $t->repository->request($request)['status']);
    }
}
