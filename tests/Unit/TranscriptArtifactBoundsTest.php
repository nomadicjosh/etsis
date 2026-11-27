<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Communications\Service\MessageCipher;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptArtifactRepository;
use Domain\Transcript\Service\TranscriptArtifactReader;
use Domain\Transcript\Service\TranscriptArtifactVerifier;
use PHPUnit\Framework\TestCase;

final class TranscriptArtifactBoundsTest extends TestCase
{
    public function testAnOversizedCiphertextIsNotDecryptedOrReportedAsACompletedCheck(): void
    {
        $id = EntityId::generate();
        $row = ['id' => $id->value, 'request_id' => EntityId::generate()->value, 'prepared_version' => 1,
            'encrypted_content' => str_repeat('x', TranscriptArtifactReader::MAX_CIPHERTEXT + 1)];
        $cipher = $this->createMock(MessageCipher::class);
        $cipher->expects(self::never())->method('open');
        $records = $this->createMock(TranscriptArtifactRepository::class);
        $records->method('document')->willReturn($row);
        $records->expects(self::never())->method('request');
        $records->expects(self::never())->method('delivery');
        $result = new TranscriptArtifactVerifier($records, new TranscriptArtifactReader($cipher))->verify($id);
        self::assertSame('limited', $result['status']);
        self::assertFalse($result['preparation_checked']);
        self::assertFalse($result['delivery_checked']);
        self::assertSame([], $result['issues']);
    }
}
