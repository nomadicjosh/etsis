<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptLayoutCatalog;
use Domain\Transcript\ValueObject\TranscriptLayout;
use Infrastructure\Documents\RegisteredTranscriptLayouts;
use PHPUnit\Framework\TestCase;
use TranscriptFixture;

final class TranscriptLayoutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/TranscriptFixture.php';
    }

    private function renderer(?callable $html = null): TranscriptDocumentRenderer
    {
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method('pdf')->willReturn('%PDF-1.4 Fictional retained transcript');
        $renderer->method('html')->willReturnCallback($html ?? static fn(): string =>
            '<!doctype html><html lang="en"><title>Fictional transcript</title><p>Retained original</p></html>');
        return $renderer;
    }

    private function history(TranscriptFixture $t): array
    {
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        return array_map($t->sis->sql->rows(...), $tables);
    }

    public function testLayoutCatalogHasExplicitPaperChoicesAndRejectsPathsAndUnknownDefaults(): void
    {
        $catalog = new RegisteredTranscriptLayouts();
        self::assertCount(4, $catalog->available());
        self::assertSame('classic-letter', $catalog->get()->code);
        self::assertSame('modern-a4', new RegisteredTranscriptLayouts('modern-a4')->get()->code);
        foreach ($catalog->available() as $layout) {
            self::assertSame($layout->describe(), TranscriptLayout::fromArray($layout->describe())->describe());
        }
        foreach (['../transcript-document.phtml', 'https://example.invalid/layout', 'missing'] as $code) {
            try {
                $catalog->get($code);
                self::fail('An unregistered layout was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        new RegisteredTranscriptLayouts('not-installed')->get();
    }

    public function testModernA4PreviewAndPreparationRetainActualHtmlAndPdfWithoutAcademicChanges(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service();
        $history = $this->history($t);
        $review = $service->review($request, 'modern-a4');
        $operation = new EntityId($review['operation_id']);
        self::assertSame('a4', $review['layout']['paper']);
        self::assertArrayNotHasKey('presentation', $review['snapshot']);
        $preview = $service->preview($request, $operation, $review['revision'], $review['review_token'], 'modern-a4');
        self::assertStringContainsString('Preview only.', $preview);
        self::assertStringContainsString('transcript-modern', $preview);
        self::assertStringContainsString('size: A4;', $preview);
        self::assertStringContainsString('Content-Security-Policy', $preview);
        self::assertSame('pending', $t->repository->request($request)['status']);
        self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
        $saved = $service->prepare(
            $request,
            $operation,
            $review['revision'],
            true,
            $review['review_token'],
            'modern-a4',
        );
        $html = $service->html($request, $operation);
        self::assertStringContainsString('Official academic transcript', $html);
        self::assertStringContainsString('Modern · A4', $html);
        self::assertStringNotContainsString('This transcript has not been prepared or issued', $html);
        self::assertSame($review['layout'], $service->view($request, $operation)['snapshot']['presentation']);
        $pdf = $service->pdf($request, $operation);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+595\./', $pdf);
        self::assertSame($saved['pdf_hash'], hash('sha256', $pdf));
        self::assertSame($history, $this->history($t));
        $encrypted = $t->repository->document($operation)['encrypted_content'];
        self::assertStringNotContainsString('Fictional University', $encrypted);
        self::assertStringNotContainsString('<!doctype html>', $encrypted);
    }

    public function testCurrentLayoutChangesDoNotRerenderOriginalsInvalidateDeliveryOrBreakAcceptedRetries(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request, 'modern-a4');
        $id = new EntityId($review['operation_id']);
        $saved = $service->prepare($request, $id, $review['revision'], true, $review['review_token'], 'modern-a4');
        $html = $service->html($request, $id);
        $pdf = $service->pdf($request, $id);
        $renderer = $this->createMock(TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method('html');
        $renderer->expects(self::never())->method('pdf');
        $catalog = $this->createMock(TranscriptLayoutCatalog::class);
        $catalog->expects(self::never())->method('get');
        $later = $t->service($renderer, layouts: $catalog);
        $t->sis->clock->time = $t->sis->clock->time->modify('+1 day');
        self::assertSame($saved, $later->prepare(
            $request,
            $id,
            $review['revision'],
            true,
            $review['review_token'],
            'modern-a4'
        ));
        self::assertSame($html, $later->html($request, $id));
        self::assertSame($pdf, $later->pdf($request, $id));
        self::assertFalse($later->view($request, $id)['academic_changed']);
        $at = $t->sis->clock->now()->modify('-1 minute');
        $delivery = $later->reviewDelivery($request, $id, 1, $at, 'Fictional handoff');
        self::assertSame('fulfilled', $later->deliver(
            $request,
            $id,
            new EntityId($delivery['operation_id']),
            1,
            $at,
            'Fictional handoff',
            $delivery['review_token']
        )['status']);
    }

    public function testDifferentLayoutOrChangedImplementationRequiresFreshReviewBeforeRendering(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $real = new RegisteredTranscriptLayouts();
        $changed = false;
        $catalog = $this->createStub(TranscriptLayoutCatalog::class);
        $catalog->method('available')->willReturn($real->available());
        $catalog->method('get')->willReturnCallback(static function (?string $code = null) use ($real, &$changed) {
            $layout = $real->get($code);
            return $changed ? new TranscriptLayout(
                $layout->code,
                $layout->label,
                $layout->paper,
                str_repeat('a', 64)
            ) : $layout;
        });
        $renderer = $this->createMock(TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method('html');
        $renderer->expects(self::never())->method('pdf');
        $service = $t->service($renderer, layouts: $catalog);
        $review = $service->review($request, 'modern-a4');
        $id = new EntityId($review['operation_id']);
        try {
            $service->prepare($request, $id, $review['revision'], true, $review['review_token'], 'classic-letter');
            self::fail('A different presentation bypassed the review.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
        }
        $changed = true;
        $this->expectException(ConcurrentModificationException::class);
        $service->prepare($request, $id, $review['revision'], true, $review['review_token'], 'modern-a4');
    }

    public function testPreviewIsActorRequestOperationAndExpiryBoundAndNeverIssuesADocument(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        try {
            $service->preview($request, EntityId::generate(), $review['revision'], $review['review_token']);
            self::fail('An unrelated operation used a preview proof.');
        } catch (ValidationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
        }
        $t->sis->clock->time = $t->sis->clock->time->modify('+20 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $service->preview($request, $id, $review['revision'], $review['review_token']);
    }

    public function testPermissionAndTimeChangesDuringPreviewDiscardTheResult(): void
    {
        foreach (['authority', 'time'] as $change) {
            $t = new TranscriptFixture();
            $request = $t->request();
            $renderer = $this->renderer(static function () use ($t, $change): string {
                if ($change === 'time') {
                    $t->sis->clock->time = $t->sis->clock->time->modify('+20 minutes');
                } else {
                    $t->sis->access->staff = false;
                    $t->sis->access->grants = [];
                }
                return '<p>Fictional private preview</p>';
            });
            $service = $t->service($renderer);
            $review = $service->review($request);
            try {
                $service->preview(
                    $request,
                    new EntityId($review['operation_id']),
                    $review['revision'],
                    $review['review_token']
                );
                self::fail('An expired or unauthorized preview escaped.');
            } catch (AccessDeniedException | ConcurrentModificationException) {
                self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
            }
        }
    }

    public function testOversizedOrInvalidHtmlRollsBackPreparationBeforePdfRendering(): void
    {
        foreach ([str_repeat('x', 1024 * 1024 + 1), "\xB1"] as $invalid) {
            $t = new TranscriptFixture();
            $request = $t->request();
            $renderer = $this->createMock(TranscriptDocumentRenderer::class);
            $renderer->method('html')->willReturn($invalid);
            $renderer->expects(self::never())->method('pdf');
            $service = $t->service($renderer);
            $review = $service->review($request);
            $history = $this->history($t);
            try {
                $service->prepare(
                    $request,
                    new EntityId($review['operation_id']),
                    $review['revision'],
                    true,
                    $review['review_token']
                );
                self::fail('Invalid accessible output became an official document.');
            } catch (ValidationException) {
                self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
                self::assertSame($history, $this->history($t));
                self::assertSame('pending', $t->repository->request($request)['status']);
            }
        }
    }

    public function testMovedOrAlteredAccessibleArtifactsFailBeforeAnyPrivateReadIsReturned(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service($this->renderer());
        $review = $service->review($request);
        $id = new EntityId($review['operation_id']);
        $service->prepare($request, $id, $review['revision'], true, $review['review_token']);
        $row = $t->repository->document($id);
        $original = json_decode($t->campus->cipher->open($row['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        foreach (['hash', 'scope', 'missing'] as $case) {
            $payload = $original;
            if ($case === 'hash') {
                $payload['html'] = '<p>Changed private artifact</p>';
            } elseif ($case === 'scope') {
                $payload['document']['document_id'] = EntityId::generate()->value;
            } else {
                unset($payload['html']);
            }
            $t->sis->sql->update('sis_transcript_documents', ['encrypted_content' =>
                $t->campus->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR))], ['id' => $id->value]);
            try {
                $service->html($request, $id);
                self::fail('A modified original was returned.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCombinedArtifactBoundRejectsLargeOutputWithoutSavingPartialEvidence(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method('pdf')->willReturn('%PDF-' . str_repeat('p', 4 * 1024 * 1024 - 5));
        $renderer->method('html')->willReturn(str_repeat('"', 512 * 1024));
        $service = $t->service($renderer);
        $review = $service->review($request);
        try {
            $service->prepare(
                $request,
                new EntityId($review['operation_id']),
                $review['revision'],
                true,
                $review['review_token']
            );
            self::fail('An oversized combined artifact reached persistence.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('combined transcript', $error->errors[0]['message']);
            self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
            self::assertSame('pending', $t->repository->request($request)['status']);
        }
    }
}
