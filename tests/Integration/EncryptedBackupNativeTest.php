<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Backup\BackupService;
use Domain\Shared\EventSourcing\HistoryAnchorService;
use Infrastructure\Backup\LazyBackupHistory;
use Infrastructure\Backup\NativeDatabaseSource;
use Infrastructure\Backup\PrivateBackupPaths;
use Infrastructure\Backup\ResticBackupEngine;
use Infrastructure\Backup\ResticProcess;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier;
use Infrastructure\EventSourcing\SqlHistoryInventory;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\BackupFixture;
use Tests\Support\NativeQualificationDatabase;

final class EncryptedBackupNativeTest extends TestCase
{
    public function testPostgresBackupRestoresFictionalBusinessTablesAndItsIndependentJournalCheckpoint(): void
    {
        if (
            getenv("ETSIS_WORKFLOW_NATIVE_QUALIFICATION") !== "1" ||
            !extension_loaded("pdo_pgsql") ||
            !is_executable((string) getenv("ETSIS_TEST_RESTIC"))
        ) {
            self::markTestSkipped(
                "Native encrypted backup qualification requires explicit private fixture settings.",
            );
        }
        require_once dirname(__DIR__) . "/Support/BackupFixture.php";
        require_once dirname(__DIR__) .
        "/Support/NativeQualificationDatabase.php";
        $f = new BackupFixture(getenv("ETSIS_TEST_RESTIC"));
        $source = $target = null;
        try {
            mkdir($f->root . "/native-source", 0700);
            mkdir($f->root . "/native-target", 0700);
            $source = new NativeQualificationDatabase(
                $f->root . "/native-source",
            );
            $source->seed($f->history->sis);
            $sourceConnection = $source->connection();
            $settings = $source->workerSettings();
            $sql = new SqlRecords($sourceConnection->queryBuilder());
            $schemas = new HistoricalSchemaRegistry();
            $store = new SqlHistoricalEventStore(
                $sql,
                new DatabaseUnitOfWork($sourceConnection->queryBuilder()),
                $f->history->sis->clock,
                $f->history->codec,
                $schemas,
            );
            $anchorService = new HistoryAnchorService(
                new SqlHistoryInventory($sql),
                new SqlHistoricalIntegrityVerifier(
                    $sql,
                    $store,
                    $f->history->codec,
                    $schemas,
                ),
                $f->history->sis->clock,
            );
            $binary = realpath(
                getenv("ETSIS_TEST_PG_DUMP") ?: "/usr/bin/pg_dump",
            );
            $engine = new ResticBackupEngine(
                $f->config,
                new ResticProcess($f->config),
                new NativeDatabaseSource($settings, ["pgsql" => $binary]),
                new PrivateBackupPaths($f->project . "/public"),
            );
            $service = new BackupService(
                $engine,
                $f->files,
                new LazyBackupHistory(
                    static fn() => $anchorService,
                    $f->storage,
                ),
                $f->maintenance,
            );
            $before = $this->facts($sourceConnection->pdo);
            $engine->initialize($f->config->budget());
            $captured = $service->capture(
                $f->config->plan,
                $f->config->budget(),
                true,
            );
            $manifest = $engine->read(
                $captured["snapshot"],
                $f->config->budget(),
            );
            self::assertSame("pgsql", $manifest->data["driver"]);
            $destination = $f->root . "/recovery";
            self::assertSame(
                "restored",
                $service->restore(
                    $captured["snapshot"],
                    $destination,
                    "fictional-campus",
                    $f->config->budget(),
                )["status"],
            );
            $target = new NativeQualificationDatabase(
                $f->root . "/native-target",
            );
            $targetSettings = $target->workerSettings();
            $native = new NativeDatabaseSource($targetSettings, [
                "pgsql" => $binary,
            ]);
            $environment = $native->export()["environment"];
            $environment["PGOPTIONS"] =
            "-c statement_timeout=10000 -c lock_timeout=1500";
            $restore = dirname($binary) . "/pg_restore";
            $listing = new Process(
                [$restore, "--list", $destination . "/database.dump"],
                timeout: 5,
            );
            $listing->run();
            self::assertSame(0, $listing->getExitCode());
            // Both disposable databases retain their own cleanup guards; these are test support, never SIS facts.
            $toc = array_filter(
                explode("\n", $listing->getOutput()),
                static fn(string $line): bool => !str_contains(
                    $line,
                    "etsis_qualification_owner",
                ),
            );
            $listPath = $f->root . "/restore.toc";
            file_put_contents($listPath, implode("\n", $toc));
            chmod($listPath, 0600);
            $process = new Process(
                [
                    $restore,
                    "--no-owner",
                    "--no-privileges",
                    "--exit-on-error",
                    "--single-transaction",
                    "--use-list=" . $listPath,
                    "--dbname=" . $environment["PGDATABASE"],
                    $destination . "/database.dump",
                ],
                env: $environment,
                timeout: 15,
            );
            $process->disableOutput();
            $process->run();
            self::assertSame(
                0,
                $process->getExitCode(),
                "Fictional PostgreSQL restore did not complete.",
            );
            self::assertSame($before, $this->facts($target->connection()->pdo));
            self::assertSame($before, $this->facts($sourceConnection->pdo));
            $restoredSql = new SqlRecords(
                $target->connection()->queryBuilder(),
            );
            $recoveredKeys = json_decode(
                file_get_contents(
                    $destination . "/files" . $f->project . "/event-keys.json",
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $recoveredConfiguration = \Qubus\Config\Collection::factory([]);
            $recoveredConfiguration->setConfigKey("event_history", [
                "active_key_id" => "v1",
                "keys" => $recoveredKeys,
            ]);
            $recoveredCodec = new \Infrastructure\EventSourcing\ProtectedEventCodec(
                $recoveredConfiguration,
            );
            $restoredStore = new SqlHistoricalEventStore(
                $restoredSql,
                new DatabaseUnitOfWork($target->connection()->queryBuilder()),
                $f->history->sis->clock,
                $recoveredCodec,
                $schemas,
            );
            $verifier = new HistoryAnchorService(
                new SqlHistoryInventory($restoredSql),
                new SqlHistoricalIntegrityVerifier(
                    $restoredSql,
                    $restoredStore,
                    $recoveredCodec,
                    $schemas,
                ),
                $f->history->sis->clock,
            );
            $anchor = $f->storage->read(
                $f->root . "/checkpoints/" . $captured["bundle"] . ".anchor",
                $f->config->plan->checkpointKey,
            );
            self::assertTrue($verifier->compare($anchor, "fictional-campus"));
            self::assertSame(3, $anchor->data["inventory"]["events"]);
        } finally {
            $target?->close();
            $source?->close();
            $f->dispose();
        }
    }

    private function facts(\PDO $pdo): array
    {
        $tables = $pdo
            ->query(
                "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() "
                . "AND tablename <> 'etsis_qualification_owner' ORDER BY tablename",
            )
            ->fetchAll(\PDO::FETCH_COLUMN);
        $facts = [];
        foreach ($tables as $table) {
            $q = '"' . str_replace('"', '""', $table) . '"';
            $rows = $pdo
                ->query("SELECT row_to_json(t)::text FROM " . $q . " t")
                ->fetchAll(\PDO::FETCH_COLUMN);
            sort($rows, SORT_STRING);
            $facts[$table] = [
                "count" => count($rows),
                "sha256" => hash("sha256", implode("\n", $rows)),
            ];
        }
        return $facts;
    }
}
