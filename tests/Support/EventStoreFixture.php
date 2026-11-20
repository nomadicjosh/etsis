<?php

declare(strict_types=1);

use Defuse\Crypto\Key;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\PdoTransactionalEventStore;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\SystemClock;
use Qubus\Config\Collection;
use Qubus\Expressive\Database;

final class EventStoreFixture
{
    /** @var WeakMap<PDO, Collection>|null */
    private static ?WeakMap $configs = null;

    /** Streaming test fingerprint avoids retaining dozens of complete encrypted event inventories. */
    public static function historyDigest(SqlRecords $sql): string
    {
        $hash = hash_init('sha256');
        $after = null;
        do {
            $rows = $sql->fetch(
                'SELECT * FROM sis_history_events' .
                ($after === null ? '' : ' WHERE id > ?') . ' ORDER BY id',
                $after === null ? [] : [$after],
                20
            );
            foreach ($rows as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
                $after = $row['id'];
            }
        } while (count($rows) === 20);
        return hash_final($hash);
    }

    public static function config(Database $db): Collection
    {
        self::$configs ??= new WeakMap();
        $pdo = $db->getConnection()->pdo;
        if (!isset(self::$configs[$pdo])) {
            $config = Collection::factory([]);
            $config->setConfigKey("event_history", [
                "active_key_id" => "v1",
                "keys" => [
                    "v1" => Key::createNewRandomKey()->saveToAsciiSafeString(),
                ],
            ]);
            self::$configs[$pdo] = $config;
        }
        return self::$configs[$pdo];
    }

    /** Share only fictional in-memory keys with an isolated connection in concurrency tests. */
    public static function shareConfig(Database $db, Collection $config): void
    {
        self::$configs ??= new WeakMap();
        self::$configs[$db->getConnection()->pdo] = $config;
    }

    public static function history(Database $db): SqlHistoricalEventStore
    {
        return new SqlHistoricalEventStore(
            new SqlRecords($db),
            new DatabaseUnitOfWork($db),
            new SystemClock(),
            new ProtectedEventCodec(self::config($db)),
            new HistoricalSchemaRegistry()
        );
    }

    public static function financialWriter(Database $db): \Infrastructure\EventSourcing\FinancialEventWriter
    {
        return new \Infrastructure\EventSourcing\FinancialEventWriter(self::history($db), new HistoricalSchemaRegistry());
    }

    public static function framework(Database $db): PdoTransactionalEventStore
    {
        $transactions = new DatabaseUnitOfWork($db);
        $schemas = new HistoricalSchemaRegistry();
        $history = new SqlHistoricalEventStore(
            new SqlRecords($db),
            $transactions,
            new SystemClock(),
            new ProtectedEventCodec(self::config($db)),
            $schemas,
        );
        return new PdoTransactionalEventStore($history, $transactions, $schemas);
    }
}
