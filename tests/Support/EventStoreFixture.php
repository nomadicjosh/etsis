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
