<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptArtifactRepository;
use Domain\Transcript\Service\TranscriptArtifactReader;
use Domain\Transcript\Service\TranscriptArtifactVerifier;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Infrastructure\Persistence\Repository\SqlTranscriptArtifactRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TranscriptFixture;

final class TranscriptArtifactVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/TranscriptFixture.php';
    }

    /** Genuine preparation with a small fictional document; no institutional data or external renderer. */
    private function prepared(bool $delivered = false): array
    {
        $t = new TranscriptFixture();
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method('pdf')->willReturn('%PDF-1.4 Fictional original transcript');
        $renderer->method('html')->willReturn('<html lang="en"><title>Fictional original</title></html>');
        $service = $t->service($renderer);
        $request = $t->request();
        $review = $service->review($request);
        $document = new EntityId($review['operation_id']);
        $service->prepare($request, $document, $review['revision'], true, $review['review_token']);
        if ($delivered) {
            $at = $t->sis->clock->now()->modify('-1 minute');
            $delivery = $service->reviewDelivery($request, $document, 1, $at, 'Fictional handoff evidence.');
            $service->deliver(
                $request,
                $document,
                new EntityId($delivery['operation_id']),
                1,
                $at,
                'Fictional handoff evidence.',
                $delivery['review_token']
            );
        }
        return [$t, $request, $document];
    }

    private function verifier(
        TranscriptFixture $t,
        ?TranscriptArtifactRepository $records = null,
    ): TranscriptArtifactVerifier {
        return new TranscriptArtifactVerifier(
            $records ?? new SqlTranscriptArtifactRepository($t->sis->sql),
            new TranscriptArtifactReader($t->campus->cipher)
        );
    }

    public function testIssuedOriginalAndActualDeliveryVerifyWithAllWritesProhibitedAndPrivateOutput(): void
    {
        [$t, $request, $document] = $this->prepared(delivered: true);
        $tables = ['sis_transcript_requests', 'sis_transcript_documents', 'sis_transcript_deliveries',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit',
            'sis_transcript_updates'];
        $before = array_map($t->sis->sql->rows(...), $tables);
        $t->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($t)->verify($document);
        self::assertSame('verified', $result['status']);
        self::assertTrue($result['preparation_checked']);
        self::assertTrue($result['delivery_checked']);
        self::assertTrue($result['preparation_release_recorded']);
        self::assertTrue($result['delivery_release_recorded']);
        self::assertFalse($result['records_changed']);
        self::assertSame($before, array_map($t->sis->sql->rows(...), $tables));
        $output = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['Fictional', '%PDF-', '<html', $request->value, $before[1][0]['pdf_hash'],
            $before[1][0]['encrypted_content']] as $private
        ) {
            self::assertStringNotContainsString($private, $output);
        }
    }

    public function testLaterAcademicDecisionsDoNotReinterpretAnOriginalArtifact(): void
    {
        [$t, $request, $document] = $this->prepared();
        $t->postGrade($request);
        $history = \EventStoreFixture::historyDigest($t->sis->sql);
        $t->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($t)->verify($document);
        self::assertSame('verified', $result['status']);
        self::assertFalse($result['delivery_present']);
        self::assertFalse($result['delivery_checked']);
        self::assertSame($history, \EventStoreFixture::historyDigest($t->sis->sql));
    }

    public static function corruptions(): array
    {
        return array_map(static fn(string $kind): array => [$kind], [
            'ciphertext', 'html', 'pdf', 'pdf_format', 'document_binding', 'date_binding', 'release_evidence',
        ]);
    }

    #[DataProvider('corruptions')]
    public function testCorruptOrAuthenticatedButInconsistentOriginalsAreNotVerified(string $kind): void
    {
        [$t, , $document] = $this->prepared();
        $row = $t->repository->document($document);
        $payload = json_decode($t->campus->cipher->open($row['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        $changes = [];
        switch ($kind) {
            case 'ciphertext':
                $changes['encrypted_content'] = 'Fictional damaged ciphertext';
                break;
            case 'html':
                $payload['html'] = '<html>Fictional changed original</html>';
                break;
            case 'pdf':
                $payload['pdf'] = base64_encode('%PDF-1.4 Fictional changed original');
                break;
            case 'pdf_format':
                $payload['pdf'] = base64_encode('Fictional invalid PDF');
                $changes['pdf_hash'] = hash('sha256', 'Fictional invalid PDF');
                break;
            case 'document_binding':
                $payload['document']['document_id'] = EntityId::generate()->value;
                break;
            case 'date_binding':
                $changes['created_at'] = '2020-01-01T00:00:00+00:00';
                break;
            case 'release_evidence':
                $payload['release_review'] = [];
                break;
        }
        $changes['encrypted_content'] ??= $t->campus->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR));
        $t->sis->sql->update('sis_transcript_documents', $changes, ['id' => $document->value]);
        $before = $t->repository->document($document);
        $t->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($t)->verify($document);
        self::assertSame('unverified', $result['status']);
        self::assertSame(['document'], $result['issues']);
        self::assertFalse($result['preparation_checked']);
        self::assertSame($before, $t->repository->document($document));
    }

    public function testDeliveryEvidenceCannotBeTransplantedToAnotherRequest(): void
    {
        [$t, , $document] = $this->prepared(delivered: true);
        $row = $t->repository->documentDelivery($document);
        $payload = json_decode($t->campus->cipher->open($row['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        $payload['request']['request_id'] = $payload['result']['request_id'] = EntityId::generate()->value;
        $t->sis->sql->update('sis_transcript_deliveries', [
            'encrypted_content' => $t->campus->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR)),
        ], ['id' => $row['id']]);
        $t->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($t)->verify($document);
        self::assertSame('unverified', $result['status']);
        self::assertSame(['delivery'], $result['issues']);
        self::assertFalse($result['preparation_checked']);
        self::assertFalse($result['delivery_checked']);
    }

    public function testGenuinelyEarlierPacketsKeepUnrecordedReleaseEvidenceExplicit(): void
    {
        [$t, , $document] = $this->prepared(delivered: true);
        foreach (['sis_transcript_documents', 'sis_transcript_deliveries'] as $table) {
            $row = $t->sis->sql->rows($table)[0];
            $payload = json_decode(
                $t->campus->cipher->open($row['encrypted_content']),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            unset($payload['release_review']);
            $t->sis->sql->update($table, [
                'encrypted_content' => $t->campus->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], ['id' => $row['id']]);
        }
        $t->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($t)->verify($document);
        self::assertSame('verified', $result['status']);
        self::assertFalse($result['preparation_release_recorded']);
        self::assertFalse($result['delivery_release_recorded']);
    }

    public function testAConcurrentRequestChangeIsNotReportedAsArtifactCorruption(): void
    {
        [$t, $request, $document] = $this->prepared();
        $real = new SqlTranscriptArtifactRepository($t->sis->sql);
        $reads = 0;
        $records = $this->createStub(TranscriptArtifactRepository::class);
        $records->method('document')->willReturnCallback($real->document(...));
        $records->method('delivery')->willReturnCallback($real->delivery(...));
        $records->method('request')->willReturnCallback(static function (
            EntityId $id
        ) use (
            $t,
            $real,
            &$reads
): ?array {
            if (++$reads === 2) {
                $t->sis->sql->update('sis_transcript_requests', ['version' => 2], ['id' => $id->value]);
            }
            return $real->request($id);
        });
        $result = $this->verifier($t, $records)->verify($document);
        self::assertSame('changed', $result['status']);
        self::assertSame([], $result['issues']);
        self::assertFalse($result['preparation_checked']);
        self::assertSame(2, (int) $t->repository->request($request)['version']);
    }

    public function testMissingReferencesAndInheritedTransactionsCannotSucceed(): void
    {
        [$t, , $document] = $this->prepared();
        $real = new SqlTranscriptArtifactRepository($t->sis->sql);
        $records = $this->createStub(TranscriptArtifactRepository::class);
        $records->method('document')->willReturnCallback($real->document(...));
        $records->method('request')->willReturn(null);
        $result = $this->verifier($t, $records)->verify($document);
        self::assertSame(['request'], $result['issues']);
        self::assertSame('unverified', $result['status']);
        self::assertSame(['document'], $this->verifier($t)->verify(EntityId::generate())['issues']);
        $t->sis->connection->pdo->beginTransaction();
        try {
            self::assertSame(['unavailable'], $this->verifier($t)->verify($document)['issues']);
        } finally {
            $t->sis->connection->pdo->rollBack();
        }
    }

    public function testAnIsolatedDatabaseRestoreRequiresBothJournalAndArtifactKeyRecovery(): void
    {
        [$t, , $document] = $this->prepared(delivered: true);
        $directory = sys_get_temp_dir() . '/etsis-transcript-restore-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/web', 0700);
        $path = $directory . '/restored.sqlite';
        $key = $directory . '/independent.key';
        $checkpoint = $directory . '/boundary.anchor';
        try {
            $before = \EventStoreFixture::historyDigest($t->sis->sql);
            $documents = $t->sis->sql->rows('sis_transcript_documents');
            $t->sis->sql->execute('VACUUM INTO ?', [$path]);
            chmod($path, 0600);
            $config = \EventStoreFixture::config($t->sis->connection->queryBuilder());
            $source = $this->anchor($t, $t->sis->connection->queryBuilder(), $config);
            $storage = new \Infrastructure\Operations\LocalHistoryAnchorStorage($config, $directory . '/web');
            $storage->generateKey($key);
            $storage->write($checkpoint, $key, $source->capture('fictional-transcript-restore'));
            $anchor = $storage->read($checkpoint, $key);

            $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
                'driver' => 'pdo_sqlite', 'dsn' => 'sqlite:' . $path,
            ]);
            $connection->pdo->exec('PRAGMA query_only = ON');
            $sql = new \Infrastructure\Persistence\SqlRecords($connection->queryBuilder());
            $restoredAnchor = $this->anchor($t, $connection->queryBuilder(), $config);
            self::assertTrue($restoredAnchor->compare($anchor, 'fictional-transcript-restore'));
            $repository = new SqlTranscriptArtifactRepository($sql);
            $restored = new TranscriptArtifactVerifier($repository, new TranscriptArtifactReader($t->campus->cipher));
            self::assertSame('verified', $restored->verify($document)['status']);
            self::assertSame($before, \EventStoreFixture::historyDigest($sql));

            $appKeys = [['crypto_key' => ''],
                ['crypto_key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString()]];
            foreach ($appKeys as $app) {
                $missing = \Qubus\Config\Collection::factory([]);
                $missing->setConfigKey('app', $app);
                $cipher = new \Infrastructure\Communications\DefuseMessageCipher($missing);
                self::assertSame('unverified', new TranscriptArtifactVerifier(
                    $repository,
                    new TranscriptArtifactReader($cipher)
                )->verify($document)['status']);
                // Journal keys alone do not recover separately encrypted document content.
                self::assertTrue($restoredAnchor->compare($anchor, 'fictional-transcript-restore'));
            }
            self::assertSame('verified', $restored->verify($document)['status']);

            $eventKeys = $config->getConfigKey('event_history');
            $config->setConfigKey('event_history', ['active_key_id' => 'v1', 'keys' => []]);
            try {
                $restoredAnchor->compare($anchor, 'fictional-transcript-restore');
                self::fail('Missing journal keys established full recovery.');
            } catch (\Domain\Shared\EventSourcing\HistoryVerificationFailedException) {
                self::assertSame('verified', $restored->verify($document)['status']);
            } finally {
                $config->setConfigKey('event_history', $eventKeys);
            }
            self::assertTrue($restoredAnchor->compare($anchor, 'fictional-transcript-restore'));

            $connection->pdo->exec('PRAGMA query_only = OFF');
            $sql->update(
                'sis_transcript_documents',
                ['encrypted_content' => 'Fictional missing artifact'],
                ['id' => $document->value]
            );
            $connection->pdo->exec('PRAGMA query_only = ON');
            self::assertTrue($restoredAnchor->compare($anchor, 'fictional-transcript-restore'));
            self::assertSame('unverified', $restored->verify($document)['status']);
            self::assertSame($before, \EventStoreFixture::historyDigest($t->sis->sql));
            self::assertSame($documents, $t->sis->sql->rows('sis_transcript_documents'));
        } finally {
            foreach ([$path, $key, $checkpoint] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . '/web');
            rmdir($directory);
        }
    }

    private function anchor(
        TranscriptFixture $t,
        \Qubus\Expressive\Database $database,
        \Qubus\Config\Collection $config,
    ): \Domain\Shared\EventSourcing\HistoryAnchorService {
        $codec = new \Infrastructure\EventSourcing\ProtectedEventCodec($config);
        $schemas = new \Infrastructure\EventSourcing\HistoricalSchemaRegistry();
        $sql = new \Infrastructure\Persistence\SqlRecords($database);
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $sql,
            new \Infrastructure\Persistence\DatabaseUnitOfWork($database),
            $t->sis->clock,
            $codec,
            $schemas
        );
        return new \Domain\Shared\EventSourcing\HistoryAnchorService(
            new \Infrastructure\EventSourcing\SqlHistoryInventory($sql),
            new \Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier($sql, $history, $codec, $schemas),
            $t->sis->clock
        );
    }
}
