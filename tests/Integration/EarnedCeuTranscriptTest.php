<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Documents\ProtectedTranscriptRenderer;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EarnedCeuFixture;

final class EarnedCeuTranscriptTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EarnedCeuFixture.php';
    }

    public static function academicQuantities(): array
    {
        return [[0], [3000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('academicQuantities')]
    public function testCeusAreIsolatedFromAcademicTranscriptsAndEarlierPreparedCeuDocumentsStayExact(
        int $academicMilli
    ): void {
        $f = new EarnedCeuFixture(academicMilli: $academicMilli);
        $s = $f->sis;
        $records = new SqlTranscriptRepository($s->sql);
        $request = EntityId::generate();
        $owner = $s->admissions->person($s->admissions->student($f->student)->personId)->ownerUserId;
        new SqlCampusRepository($s->sql, $s->clock, new \Infrastructure\Portal\SymfonyContentMarkup())
            ->addTranscriptRequest($request, ['student_id' => $f->student->value, 'owner_user_id' => $owner->value,
                'delivery' => 'pickup', 'encrypted_recipient' => $s->dropCipher->seal(''), 'status' => 'pending',
                'requested_at' => $s->clock->now()->format(DATE_ATOM), 'version' => 0]);
        $renderer = new ProtectedTranscriptRenderer();
        $service = new \Domain\Transcript\Service\TranscriptFulfillmentService(
            $records,
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            $renderer,
            new \Domain\Transcript\ValueObject\TranscriptIssuer('Fictional University', 'Fictional address'),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            $s->inputVerifier(),
            $s->profileLedger(),
            $s->primaryProgramLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader(
                \EventStoreFixture::history($s->connection->queryBuilder()),
                new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($s->sql)
            ),
            new \Infrastructure\Documents\RegisteredTranscriptLayouts(),
            new \Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository($s->sql, $s->dropCipher),
            $s->standingLedger(),
            new \Domain\Transcript\Service\TranscriptNotifications(
                new \Infrastructure\Persistence\Repository\SqlTranscriptUpdatesRepository($s->sql),
                $records,
                $s->admissions,
                $s->clock,
            ),
        );
        $f->confirm($f->review());
        $unofficial = new \Domain\Transfer\Service\TransferService(
            new \Infrastructure\Persistence\Repository\SqlTransferRepository($s->sql, $s->gradeCatalog()),
            $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository($s->sql, $s->gradeCatalog()),
            $s->transferCredits()
        );
        $academicRecord = $unofficial->transcript($f->student);
        self::assertCount($academicMilli === 0 ? 0 : 1, $academicRecord);
        foreach ($academicRecord as $row) {
            self::assertArrayNotHasKey('earned_ceus', $row);
            self::assertArrayNotHasKey('continuing_education_only', $row);
        }
        $ceuRecord = $unofficial->transcript($f->student, true);
        self::assertSame(1250, $ceuRecord[0]['earned_ceus']['earned_milli']);
        self::assertArrayNotHasKey('completed_milli', $ceuRecord[0]);
        $academic = $service->review($request);
        if ($academicMilli === 0) {
            self::assertSame([], $academic['snapshot']['credits']);
        } else {
            self::assertArrayNotHasKey('earned_ceus', $academic['snapshot']['credits'][0]);
        }
        self::assertArrayNotHasKey('purpose', $academic['snapshot']);
        $ceu = $service->review($request, 'ceu-letter');
        self::assertSame('continuing_education', $ceu['snapshot']['purpose']);
        self::assertSame(['earned_ceu_milli' => 1250], $ceu['snapshot']['totals']);
        self::assertArrayNotHasKey('completed_milli', $ceu['snapshot']['credits'][0]);
        $html = $service->preview(
            $request,
            new EntityId($ceu['operation_id']),
            $ceu['revision'],
            $ceu['review_token'],
            'ceu-letter'
        );
        self::assertStringContainsString('Continuing education transcript', $html);
        self::assertStringContainsString('1.250', $html);
        self::assertStringNotContainsString('Cumulative GPA', $html);
        $document = new EntityId($ceu['operation_id']);
        $service->prepare($request, $document, $ceu['revision'], true, $ceu['review_token'], 'ceu-letter');
        $pdf = $service->pdf($request, $document);
        self::assertStringStartsWith('%PDF-', $pdf);
        $html = $service->html($request, $document);
        $before = $s->sql->rows('sis_transcript_documents');
        $f->confirm($f->review(750));
        self::assertSame($before, $s->sql->rows('sis_transcript_documents'));
        self::assertSame($pdf, $service->pdf($request, $document));
        self::assertSame($html, $service->html($request, $document));
        self::assertSame(
            1250,
            $service->view($request, $document)['snapshot']['credits'][0]['earned_ceus']['earned_milli'],
        );
        self::assertSame(750, $service->review($request, 'ceu-a4')['snapshot']['totals']['earned_ceu_milli']);
        $f->confirm($f->review(null));
        self::assertSame([], $service->review($request, 'ceu-letter')['snapshot']['credits']);
    }
}
