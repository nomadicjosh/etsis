<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TranscriptStandingFixture;

final class TranscriptStandingPresentationTest extends TestCase
{
    private function fixture(): TranscriptStandingFixture
    {
        require_once dirname(__DIR__) . '/Support/TranscriptStandingFixture.php';
        return new TranscriptStandingFixture();
    }

    private function printed(string $html): string
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom)->evaluate('string(//section[@class="academic-standing"])');
    }

    public static function choices(): array
    {
        return [['through_cutoff', true, ['PREVIOUS', 'CURRENT']], ['through_cutoff', false, ['PREVIOUS', 'CURRENT']],
            ['effective_at_cutoff', true, ['CURRENT']], ['effective_at_cutoff', false, ['CURRENT']]];
    }

    #[DataProvider('choices')]
    public function testRealDocumentsUseTheReviewedPeriodAndDateChoicesAtTheInclusiveCutoff(
        string $periods,
        bool $dates,
        array $expected,
    ): void {
        $f = $this->fixture();
        $f->policy($periods, $dates);
        [$service, $document] = $f->prepare(pdf: true);
        $view = $service->view($f->request, $document);
        $standing = $view['snapshot']['standing'];
        self::assertSame($expected, array_column($standing['periods'], 'code'));
        self::assertSame(['periods' => $periods, 'show_dates' => $dates], $standing['presentation']);
        self::assertSame($standing['presentation'], $view['preparation_release']['standing_presentation']);
        $html = $service->html($f->request, $document);
        $printed = $this->printed($html);
        self::assertStringContainsString('CURRENT', $printed);
        self::assertSame($dates, str_contains($printed, '2026-08-01'));
        self::assertStringNotContainsString('FUTURE', $printed);
        self::assertStringNotContainsString('Private fictional', $html);
        self::assertStringContainsString('Fictional &lt;b&gt;CURRENT&lt;/b&gt;', $html);
        self::assertStringStartsWith('%PDF-', $service->pdf($f->request, $document));
    }

    public function testSelectedCurrentModeDoesNotPrintAPastSelectedStandingOrInferGoodStanding(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false, [$f->rows[0]['definition_id']]);
        [$service, $document] = $f->prepare();
        $standing = $service->view($f->request, $document)['snapshot']['standing'];
        self::assertSame([], $standing['periods']);
        self::assertTrue($standing['filtered']);
        self::assertStringContainsString(
            'No selected academic standing is included for this date.',
            $this->printed($service->html($f->request, $document))
        );
    }

    public function testNoEffectivePeriodIsExplicitlyUnknownRatherThanTheMostRecentExpiredStanding(): void
    {
        $f = $this->fixture();
        $f->rows[1]['ends_on'] = '2026-08-09';
        $f->standing();
        $f->policy('effective_at_cutoff');
        [$service, $document] = $f->prepare();
        self::assertSame([], $service->view($f->request, $document)['snapshot']['standing']['periods']);
        self::assertStringContainsString(
            'No standing is recorded for this date.',
            $this->printed($service->html($f->request, $document))
        );
    }

    public function testOriginalChoiceAndCutoffSurviveSetupChangesNewDaysAndAcceptedRetries(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false);
        [$service, $document, $review, $accepted] = $f->prepare();
        $html = $service->html($f->request, $document);
        $pdf = $service->pdf($f->request, $document);
        $f->policy();
        $f->base->sis->clock->time = $f->base->sis->clock->time->modify('+1 day');
        $before = $f->facts();
        $view = $service->view($f->request, $document);
        self::assertFalse($view['academic_changed']);
        self::assertSame('2026-08-10', $view['snapshot']['standing']['through']);
        self::assertSame(['CURRENT'], array_column($view['snapshot']['standing']['periods'], 'code'));
        self::assertSame($html, $service->html($f->request, $document));
        self::assertSame($pdf, $service->pdf($f->request, $document));
        self::assertSame($accepted, $service->prepare(
            $f->request,
            $document,
            $review['revision'],
            true,
            $review['review_token']
        ));
        $service->reviewDelivery($f->request, $document, 1, $f->base->sis->clock->now(), 'Fictional pickup.');
        self::assertSame($before, $f->facts());
        self::assertSame(['PREVIOUS', 'CURRENT', 'FUTURE'], array_column(
            $service->review($f->request)['snapshot']['standing']['periods'],
            'code'
        ));
    }

    public static function dates(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('dates')]
    public function testDateOnlyCorrectionsRequireFreshPreparationOnlyWhenPrinted(bool $dates): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', $dates);
        [$service, $document] = $f->prepare();
        $original = $service->html($f->request, $document);
        $f->rows[1]['ends_on'] = '2026-08-11';
        $f->rows[2]['starts_on'] = '2026-08-12';
        $f->standing();
        self::assertSame($dates, $service->view($f->request, $document)['academic_changed']);
        self::assertSame($original, $service->html($f->request, $document));
        if ($dates) {
            $this->expectException(ConcurrentModificationException::class);
        }
        $service->reviewDelivery($f->request, $document, 1, $f->base->sis->clock->now(), 'Fictional pickup.');
    }

    public function testHiddenDatesStillControlEffectiveMembershipAndAPrintedLabelChangeRequiresFreshPreparation(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false);
        [$service, $document] = $f->prepare();
        $f->rows[1]['ends_on'] = '2026-08-09';
        $f->standing();
        self::assertTrue($service->view($f->request, $document)['academic_changed']);
        $f->rows[1]['ends_on'] = '2026-08-10';
        $f->rows[1]['definition_id'] = $f->rows[0]['definition_id'];
        $f->rows[1]['code'] = $f->rows[0]['code'];
        $f->rows[1]['title'] = $f->rows[0]['title'];
        $f->standing();
        self::assertTrue($service->view($f->request, $document)['academic_changed']);
    }

    public function testExcludedPeriodCorrectionsPreserveAnOriginalButInvalidateAPendingReview(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false);
        [$service, $document] = $f->prepare();
        $review = $service->review($f->request);
        $f->rows[0]['ends_on'] = '2026-07-30';
        $f->standing();
        self::assertFalse($service->view($f->request, $document)['academic_changed']);
        $before = $f->facts();
        try {
            $service->prepare(
                $f->request,
                new EntityId($review['operation_id']),
                $review['revision'],
                true,
                $review['review_token']
            );
            self::fail('An unaccepted preparation ignored changed standing evidence.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testPresentationOnlySetupChangeInvalidatesAPendingReview(): void
    {
        $f = $this->fixture();
        $f->policy();
        $service = $f->service();
        $review = $service->review($f->request);
        $f->policy('effective_at_cutoff', false);
        $before = $f->facts();
        try {
            $service->prepare(
                $f->request,
                new EntityId($review['operation_id']),
                $review['revision'],
                true,
                $review['review_token']
            );
            self::fail('Unreviewed presentation changes were accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testPolicyChangesDuringRequiredDocumentAuditRollBackTheDocumentAndCoupledSetup(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(function ($actor, string $action) use ($f): void {
            if ($action === 'transcript.document_prepared') {
                $f->policy();
            }
        });
        $service = $f->base->service(audit: $audit);
        $review = $service->review($f->request);
        $before = $f->facts();
        try {
            $service->prepare(
                $f->request,
                new EntityId($review['operation_id']),
                $review['revision'],
                true,
                $review['review_token']
            );
            self::fail('A presentation change during audit escaped rollback.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testEarlierArtifactWithoutPresentationStillUsesItsOriginalAllPeriodsAndDates(): void
    {
        $f = $this->fixture();
        $f->policy();
        [$service, $document] = $f->prepare();
        $sql = $f->base->sis->sql;
        $row = $sql->one('sis_transcript_documents', ['id' => $document->value]);
        $cipher = $f->base->campus->cipher;
        $packet = json_decode($cipher->open($row['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        // Earlier authenticated packet shape, using only fictional records.
        unset(
            $packet['document']['standing']['presentation'],
            $packet['release_review']['policy']['standing_presentation']
        );
        $snapshot = $packet['document'];
        unset(
            $snapshot['document_id'],
            $snapshot['issued_at'],
            $snapshot['preview'],
            $snapshot['presentation'],
            $snapshot['standing']['stream_version']
        );
        $packet['academic_revision'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $sql->update('sis_transcript_documents', ['encrypted_content' => $cipher->seal(json_encode(
            $packet,
            JSON_THROW_ON_ERROR
        ))], ['id' => $document->value]);
        $f->policy('effective_at_cutoff', false);
        $before = $f->facts();
        $view = $service->view($f->request, $document);
        self::assertFalse($view['academic_changed']);
        self::assertArrayNotHasKey('presentation', $view['snapshot']['standing']);
        self::assertSame(['PREVIOUS', 'CURRENT'], array_column($view['snapshot']['standing']['periods'], 'code'));
        self::assertStringContainsString('2026-08-01', $this->printed($service->html($f->request, $document)));
        $service->reviewDelivery($f->request, $document, 1, $f->base->sis->clock->now(), 'Fictional pickup.');
        self::assertSame($before, $f->facts());
    }

    public function testArtifactPresentationCannotDisagreeWithTheRetainedPreparationConfirmation(): void
    {
        $f = $this->fixture();
        $f->policy('effective_at_cutoff', false);
        [$service, $document] = $f->prepare();
        $sql = $f->base->sis->sql;
        $row = $sql->one('sis_transcript_documents', ['id' => $document->value]);
        $cipher = $f->base->campus->cipher;
        $packet = json_decode($cipher->open($row['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        $packet['document']['standing']['presentation']['show_dates'] = true;
        $sql->update('sis_transcript_documents', ['encrypted_content' => $cipher->seal(json_encode(
            $packet,
            JSON_THROW_ON_ERROR
        ))], ['id' => $document->value]);
        $before = $f->facts();
        try {
            $service->html($f->request, $document);
            self::fail('A document substituted a different presentation choice.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testPresentationConfigurationIsRevisionedAndFinalAuditFailuresRollBack(): void
    {
        $f = $this->fixture();
        $original = $f->policy('effective_at_cutoff', false);
        self::assertSame($original, $f->policy('effective_at_cutoff', false));
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional required audit failed.'));
        $before = $f->facts();
        try {
            $f->policy(audit: $audit);
            self::fail('An unaudited presentation configuration committed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional required audit failed.', $error->getMessage());
            self::assertSame($before, $f->facts());
        }
    }
}
