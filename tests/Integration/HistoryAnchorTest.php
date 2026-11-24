<?php

declare(strict_types=1);

namespace Tests\Integration;

use Defuse\Crypto\Key;
use EventStoreFixture;
use FilesystemIterator;
use FixtureClock;
use HistoryVerificationFixture;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Domain\Shared\EventSourcing\HistoricalIntegrityVerifier;
use Domain\Shared\EventSourcing\HistoryAnchor;
use Domain\Shared\EventSourcing\HistoryAnchorService;
use Domain\Shared\EventSourcing\HistoryInventory;
use Domain\Shared\EventSourcing\HistoryVerificationResult;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier;
use Infrastructure\EventSourcing\SqlHistoryInventory;
use Infrastructure\Operations\LocalHistoryAnchorStorage;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;

final class HistoryAnchorTest extends TestCase
{
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/HistoryVerificationFixture.php';
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/etsis-anchor-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/web', 0700);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    private function service(HistoryVerificationFixture $f): HistoryAnchorService
    {
        return new HistoryAnchorService(
            new SqlHistoryInventory($f->sis->sql),
            $f->verifier,
            $f->sis->clock,
        );
    }

    private function storage(HistoryVerificationFixture $f): LocalHistoryAnchorStorage
    {
        return new LocalHistoryAnchorStorage(
            EventStoreFixture::config($f->sis->connection->queryBuilder()),
            $this->directory . '/web',
        );
    }

    private function restored(HistoryVerificationFixture $f, string $path): HistoryAnchorService
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite:' . $path]);
        $connection->pdo->exec('PRAGMA query_only = ON');
        $sql = new SqlRecords($connection->queryBuilder());
        $schemas = new HistoricalSchemaRegistry();
        $store = new SqlHistoricalEventStore(
            $sql,
            new DatabaseUnitOfWork($connection->queryBuilder()),
            $f->sis->clock,
            $f->codec,
            $schemas,
        );
        return new HistoryAnchorService(
            new SqlHistoryInventory($sql),
            new SqlHistoricalIntegrityVerifier($sql, $store, $f->codec, $schemas),
            $f->sis->clock,
        );
    }

    public function testEmptyAndPopulatedCheckpointsAreReadOnlyAndExplicitlyMinimized(): void
    {
        $f = new HistoryVerificationFixture();
        $service = $this->service($f);
        $before = $f->digest();
        $empty = $service->capture('fictional-installation');
        self::assertTrue($service->compare($empty, 'fictional-installation'));
        self::assertSame(0, $empty->data['inventory']['events']);
        self::assertSame($before, $f->digest());
        $stream = $f->stream(3);
        $before = $f->digest();
        $anchor = $service->capture('fictional-installation');
        self::assertSame(3, $anchor->data['inventory']['events']);
        self::assertSame(1, $anchor->data['inventory']['streams']);
        self::assertSame(1, $anchor->data['inventory']['operations']);
        self::assertTrue($service->compare($anchor, 'fictional-installation'));
        self::assertFalse($service->compare($empty, 'fictional-installation'));
        self::assertFalse($service->compare($anchor, 'other-installation'));
        self::assertSame($before, $f->digest());
        foreach (
            [$stream->aggregateId->__toString(), 'fictional-installation',
            $anchor->data['inventory']['digest'], 'Fictional confidential approval'] as $private
        ) {
            self::assertStringNotContainsString($private, json_encode($anchor, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($private, print_r($anchor, true));
            $output = json_encode($anchor->describe('matched'), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($private, $output);
        }
    }

    public function testPrivateCheckpointDetectsAnOlderValidWholeDatabaseRestore(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(2);
        $old = $this->directory . '/older.sqlite';
        $current = $this->directory . '/current.sqlite';
        $f->sis->sql->execute('VACUUM INTO ?', [$old]);
        $f->stream(3);
        $f->sis->sql->execute('VACUUM INTO ?', [$current]);
        chmod($old, 0600);
        chmod($current, 0600);
        $storage = $this->storage($f);
        $key = $this->directory . '/independent.key';
        $path = $this->directory . '/checkpoint';
        $storage->generateKey($key);
        $anchor = $this->service($f)->capture('fictional-installation');
        $storage->write($path, $key, $anchor);
        $recovered = $storage->read($path, $key);
        self::assertEquals($anchor->data, $recovered->data);
        self::assertTrue($this->restored($f, $current)->compare($recovered, 'fictional-installation'));
        // The older database has complete valid streams/receipts, but lacks an entire later stream.
        self::assertFalse($this->restored($f, $old)->compare($recovered, 'fictional-installation'));
        $sealed = file_get_contents($path);
        foreach ([$anchor->data['id'], 'fictional-installation', $anchor->data['inventory']['digest']] as $private) {
            self::assertStringNotContainsString($private, $sealed);
        }
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0600, fileperms($key) & 0777);
    }

    public function testEventKeyRotationAndRecoveryPreserveOriginalBytesAndIndependentCheckpoint(): void
    {
        $f = new HistoryVerificationFixture();
        $stream = $f->stream(3);
        $cursor = $f->store->page($stream, limit: 1)->nextCursor;
        $sql = $f->sis->sql;
        $original = $sql->rows('sis_history_events');
        $config = EventStoreFixture::config($f->sis->connection->queryBuilder());
        $old = $config->getConfigKey('event_history.keys');
        $newKey = Key::createNewRandomKey()->saveToAsciiSafeString();
        $config->setConfigKey('event_history', ['active_key_id' => 'v2', 'keys' => [...$old, 'v2' => $newKey]]);
        $f->stream(2);
        $continued = $f->store->page($stream, $cursor, 5);
        self::assertCount(2, $continued->events);
        self::assertNull($continued->nextCursor);
        foreach ($original as $row) {
            self::assertSame($row, $sql->one('sis_history_events', ['id' => $row['id']]));
        }
        $newRows = array_filter(
            $sql->rows('sis_history_events'),
            static fn(array $row): bool => !in_array($row['id'], array_column($original, 'id'), true)
        );
        foreach ($newRows as $row) {
            self::assertSame('v2', json_decode($row['sealed_event'], true, flags: JSON_THROW_ON_ERROR)['key_id']);
        }
        foreach ($sql->rows('sis_history_operations') as $operation) {
            self::assertGreaterThan(0, $f->store->verifyOperation(
                new \Domain\Shared\ValueObject\EntityId($operation['id']),
            ));
        }
        $storage = $this->storage($f);
        $key = $this->directory . '/recovery.key';
        $path = $this->directory . '/checkpoint';
        $storage->generateKey($key);
        $anchor = $this->service($f)->capture('fictional-installation');
        $storage->write($path, $key, $anchor);
        $before = $f->digest();
        foreach ([['v2' => $newKey], ['v1' => $newKey, 'v2' => $newKey]] as $badKeys) {
            $config->setConfigKey('event_history', ['keys' => $badKeys]);
            $read = $storage->read($path, $key);
            try {
                $this->service($f)->compare($read, 'fictional-installation');
                self::fail('Missing or replaced historical keys established recovery.');
            } catch (\Domain\Shared\EventSourcing\HistoryVerificationFailedException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString($newKey, (string) $exception);
            }
        }
        $config->setConfigKey('event_history', ['keys' => [...$old, 'v2' => $newKey]]);
        self::assertTrue($this->service($f)->compare($storage->read($path, $key), 'fictional-installation'));
        self::assertSame($before, $f->digest());
    }

    public function testIndependentKeyCannotReuseApplicationOrEventEncryptionKeys(): void
    {
        $f = new HistoryVerificationFixture();
        $config = EventStoreFixture::config($f->sis->connection->queryBuilder());
        $eventKey = $config->getConfigKey('event_history.keys')['v1'];
        $appKey = Key::createNewRandomKey()->saveToAsciiSafeString();
        $config->setConfigKey('app', ['crypto_key' => $appKey]);
        foreach ([$appKey, $eventKey] as $key) {
            $path = $this->directory . '/reused.key';
            file_put_contents($path, $key);
            chmod($path, 0600);
            try {
                $this->storage($f)->write($this->directory . '/checkpoint', $path, $this->service($f)->capture('test'));
                self::fail('A shared encryption key was accepted as independent.');
            } catch (RuntimeException $exception) {
                self::assertStringNotContainsString($key, (string) $exception);
                self::assertFileDoesNotExist($this->directory . '/checkpoint');
            }
        }
    }

    public function testPrivateStorageRefusesOverwritesPublicPathsSymlinksAndUnsafePermissions(): void
    {
        $f = new HistoryVerificationFixture();
        $storage = $this->storage($f);
        $key = $this->directory . '/key';
        $storage->generateKey($key);
        $original = file_get_contents($key);
        foreach ([$key, 'relative.key', $this->directory . '/web/public.key'] as $path) {
            try {
                $storage->generateKey($path);
                self::fail('An unsafe or existing destination was accepted.');
            } catch (RuntimeException) {
                self::assertSame($original, file_get_contents($key));
            }
        }
        $link = $this->directory . '/linked.key';
        symlink($key, $link);
        $anchor = $this->service($f)->capture('test');
        foreach ([$link, $key] as $index => $path) {
            if ($index === 1) {
                chmod($key, 0644);
                clearstatcache(true, $key);
            }
            try {
                $storage->write($this->directory . '/checkpoint', $path, $anchor);
                self::fail('An unsafe key file was accepted.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($this->directory . '/checkpoint');
            }
        }
    }

    public function testWrongMissingOrModifiedIndependentArtifactsFailWithoutChangingDatabase(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(2);
        $storage = $this->storage($f);
        $key = $this->directory . '/key';
        $wrong = $this->directory . '/wrong';
        $path = $this->directory . '/checkpoint';
        $storage->generateKey($key);
        $storage->generateKey($wrong);
        $storage->write($path, $key, $this->service($f)->capture('test'));
        $before = $f->digest();
        $sealed = file_get_contents($path);
        foreach ([$wrong, $this->directory . '/missing', $key, $key, $key] as $index => $candidate) {
            if ($index === 2) {
                file_put_contents($path, substr($sealed, 0, -20) . str_repeat('0', 20));
            } elseif ($index === 3) {
                file_put_contents($path, str_repeat('a', 16385));
            } elseif ($index === 4) {
                $foreign = ProtectedEventCodec::canonical(['purpose' => 'history.event.v1', 'data' => []]);
                $keyValue = Key::loadFromAsciiSafeString(trim(file_get_contents($key)));
                file_put_contents($path, \Defuse\Crypto\Crypto::encrypt($foreign, $keyValue));
            }
            try {
                $storage->read($path, $candidate);
                self::fail('Invalid private recovery material was accepted.');
            } catch (RuntimeException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString($sealed, (string) $exception);
                self::assertSame($before, $f->digest());
            }
        }
    }

    public function testInventoryLimitsAndCorruptReceiptsCannotProduceCheckpoints(): void
    {
        $f = new HistoryVerificationFixture();
        $f->stream(3);
        try {
            $this->service($f)->capture('test', 4);
            self::fail('A partial inventory produced a checkpoint.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        $row = $f->sis->sql->rows('sis_history_operations', [], 1)[0];
        $f->sis->sql->execute('DELETE FROM sis_history_operations WHERE id = ?', [$row['id']]);
        $before = $f->digest();
        try {
            $this->service($f)->capture('test');
            self::fail('A missing protected receipt produced a checkpoint.');
        } catch (\Domain\Shared\EventSourcing\HistoryVerificationFailedException) {
            self::assertSame($before, $f->digest());
        }
    }

    public function testChangingInventoryAndNeverFinishingVerificationAreRefused(): void
    {
        foreach ([false, true] as $limited) {
            $inventory = new class implements HistoryInventory {
                public int $calls = 0;
                public function fingerprint(int $rowLimit): array
                {
                    return [
                        'streams' => 0,
                        'events' => ++$this->calls,
                        'operations' => 0,
                        'digest' => str_repeat('a', 64),
                    ];
                }
            };
            $verifier = new class ($limited) implements HistoricalIntegrityVerifier {
                public int $calls = 0;
                public function __construct(private bool $limited)
                {
                }
                public function verify(
                    ?string $cursor = null,
                    int $streamLimit = 10,
                    int $eventLimit = 500,
                ): HistoryVerificationResult {
                    $this->calls++;
                    return new HistoryVerificationResult(
                        !$this->limited,
                        0,
                        0,
                        $this->limited ? 'private-cursor' : null,
                    );
                }
            };
            try {
                new HistoryAnchorService($inventory, $verifier, new FixtureClock())->capture('test');
                self::fail('Changing or incomplete verification produced an anchor.');
            } catch (RuntimeException $exception) {
                self::assertLessThanOrEqual(64, $verifier->calls);
                self::assertStringNotContainsString('private-cursor', (string) $exception);
            }
        }
    }

    public function testMalformedCheckpointContractsAndInvalidBoundsAreRejected(): void
    {
        $f = new HistoryVerificationFixture();
        $valid = $this->service($f)->capture('test')->data;
        foreach (range(0, 6) as $case) {
            $data = $valid;
            if ($case === 0) {
                $data['inventory']['digest'] = [];
            } elseif ($case === 1) {
                $data['inventory']['events'] = '0';
            } elseif ($case === 2) {
                $data['inventory']['operations'] = -1;
            } elseif ($case === 3) {
                $data['inventory']['streams'] = 10001;
            } elseif ($case === 4) {
                $data['captured_at'] = '2026-02-30T10:00:00Z';
            } elseif ($case === 5) {
                $data['scope'] = 'fictional private scope';
            } else {
                $data['private_extra'] = 'fictional-private';
            }
            try {
                new HistoryAnchor($data);
                self::fail('Malformed checkpoint data was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([0, 10001] as $limit) {
            try {
                $this->service($f)->capture('test', $limit);
                self::fail('An invalid inventory limit was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
