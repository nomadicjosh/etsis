<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Admissions\ValueObject\AdmissionEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AdmissionEvidenceTest extends TestCase
{
    public function testOldUnknownAndNewEmptyEvidenceRemainDifferentWithoutExposingDigests(): void
    {
        self::assertNull(AdmissionEvidence::display(null));
        self::assertSame([], AdmissionEvidence::display([]));
        $file = $this->file();
        self::assertSame([['name' => 'fictional.pdf']], AdmissionEvidence::display([$file]));
    }

    private function file(): array
    {
        return ['id' => EntityId::generate()->value, 'version' => 3, 'name' => 'fictional.pdf', 'bytes' => 40,
            'sha256' => hash('sha256', 'Fictional content'), 'scanned_at' => '2026-11-17T12:00:00.000000Z'];
    }

    public function testEvidenceMetadataCannotBeAttachedToAnUnrelatedEvent(): void
    {
        $event = \Domain\Finance\Event\StudentAccountWasOpened::occur(
            new \Domain\Finance\ValueObject\AccountId(),
            ['student_id' => EntityId::generate()->value,
                'term_id' => EntityId::generate()->value, 'currency' => 'USD'],
            ['__aggregate_type' => 'student_account', 'admission_evidence' => []]
        )->withPlayhead(1);
        $this->expectException(CorruptHistoryException::class);
        new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()->describe($event);
    }

    public function testMalformedOrAmbiguousProtectedEvidenceCannotReplayAsAValidSelection(): void
    {
        $file = $this->file();
        $invalid = [null, ['unexpected' => $file], [$file, $file], array_fill(0, 11, $file)];
        foreach (
            [['version' => '3'], ['bytes' => 5242881], ['name' => '../private.pdf'], ['sha256' => 'changed'],
            ['scanned_at' => '2026-02-30T12:00:00.000000Z'], ['unexpected' => 'private value']] as $change
        ) {
            $invalid[] = [array_replace($file, $change)];
        }
        foreach ($invalid as $selection) {
            try {
                AdmissionEvidence::validate($selection);
                self::fail('Malformed protected evidence was accepted.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
}
