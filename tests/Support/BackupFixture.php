<?php

declare(strict_types=1);

namespace Tests\Support;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Domain\Shared\Backup\BackupService;
use Domain\Shared\EventSourcing\HistoryAnchorService;
use Domain\Shared\Operations\MaintenanceMode;
use Domain\Shared\Operations\MaintenanceState;
use Infrastructure\Backup\BackupConfiguration;
use Infrastructure\Backup\LazyBackupHistory;
use Infrastructure\Backup\LocalBackupFiles;
use Infrastructure\Backup\NativeDatabaseSource;
use Infrastructure\Backup\PrivateBackupPaths;
use Infrastructure\Backup\ResticBackupEngine;
use Infrastructure\Backup\ResticProcess;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\SqlHistoricalIntegrityVerifier;
use Infrastructure\EventSourcing\SqlHistoryInventory;
use Infrastructure\Operations\LocalHistoryAnchorStorage;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Connection\Pdo\Sqlite;

final class BackupFixture
{
    public string $root;
    public string $project;
    public string $configurationFile;
    public BackupConfiguration $config;
    public ResticBackupEngine $engine;
    public BackupService $service;
    public LocalBackupFiles $files;
    public \HistoryVerificationFixture $history;
    public HistoryAnchorService $anchors;
    public LocalHistoryAnchorStorage $storage;
    public Sqlite $connection;
    public MaintenanceMode $maintenance;
    public array $settings;

    public function __construct(string $binary)
    {
        require_once __DIR__ . "/HistoryVerificationFixture.php";
        $this->root =
        sys_get_temp_dir() .
        "/etsis-backup-case-" .
        bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->project = $this->root . "/installation";
        $directories = [
            "installation",
            "installation/public",
            "installation/public/themes",
            "installation/storage",
            "installation/storage/app",
            "repository",
            "operator",
            "work",
            "checkpoints",
        ];
        foreach ($directories as $directory) {
            mkdir($this->root . "/" . $directory, 0700);
        }
        $this->history = new \HistoryVerificationFixture();
        $this->history->stream(3);
        $key = Key::createNewRandomKey();
        $source = [
            ".env" => "DB_PASSWORD=fictional-never-log-this\n",
            ".enc.key" => $key->saveToAsciiSafeString(),
            "storage/app/evidence.enc" => Crypto::encrypt(
                "Fictional confidential evidence.",
                $key,
            ),
            "public/themes/layout.php" =>
                "<?php /* Fictional reviewed layout. */",
        ];
        foreach ($source as $path => $text) {
            file_put_contents($this->project . "/" . $path, $text);
            chmod($this->project . "/" . $path, 0600);
        }
        $eventKeys = \EventStoreFixture::config(
            $this->history->sis->connection->queryBuilder(),
        )->getConfigKey("event_history.keys");
        file_put_contents(
            $this->project . "/event-keys.json",
            json_encode($eventKeys, JSON_THROW_ON_ERROR),
        );
        chmod($this->project . "/event-keys.json", 0600);
        $paths = new PrivateBackupPaths($this->project . "/public");
        $this->storage = new LocalHistoryAnchorStorage(
            \EventStoreFixture::config(
                $this->history->sis->connection->queryBuilder(),
            ),
            $this->project . "/public",
        );
        $this->storage->generateKey($this->root . "/operator/checkpoint.key");
        file_put_contents(
            $this->root . "/operator/password",
            "fictional-independent-restic-password-" .
                bin2hex(random_bytes(16)),
        );
        chmod($this->root . "/operator/password", 0600);
        $this->settings = [
            "scope" => "fictional-campus",
            "release" => "fictional-reviewed-source",
            "repository" => $this->root . "/repository",
            "restic" => $binary,
            "password_file" => $this->root . "/operator/password",
            "work_directory" => $this->root . "/work",
            "checkpoint_directory" => $this->root . "/checkpoints",
            "checkpoint_key" => $this->root . "/operator/checkpoint.key",
            "includes" => array_map(
                fn(string $name): string => $this->project . "/" . $name,
                [
                    ".env",
                    ".enc.key",
                    "storage/app",
                    "public/themes",
                    "event-keys.json",
                ],
            ),
            "tools" => ["sqlite" => realpath("/usr/bin/sqlite3")],
            "seconds" => 45,
            "bytes" => 16 * 1024 * 1024,
            "files" => 50,
        ];
        $this->configurationFile = $this->root . "/operator/config.json";
        $this->saveSettings();
        $this->config = BackupConfiguration::load(
            $this->configurationFile,
            $this->project,
            $this->project . "/event-keys.json",
            true,
        );
        $file = $this->root . "/source.sqlite";
        $this->history->sis->sql->execute("VACUUM INTO ?", [$file]);
        chmod($file, 0600);
        $this->connection = new Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite:" . $file,
        ]);
        $sql = new SqlRecords($this->connection->queryBuilder());
        $schemas = new HistoricalSchemaRegistry();
        $store = new SqlHistoricalEventStore(
            $sql,
            new DatabaseUnitOfWork($this->connection->queryBuilder()),
            $this->history->sis->clock,
            $this->history->codec,
            $schemas,
        );
        $this->anchors = new HistoryAnchorService(
            new SqlHistoryInventory($sql),
            new SqlHistoricalIntegrityVerifier(
                $sql,
                $store,
                $this->history->codec,
                $schemas,
            ),
            $this->history->sis->clock,
        );
        $this->engine = new ResticBackupEngine(
            $this->config,
            new ResticProcess($this->config),
            new NativeDatabaseSource(
                ["dsn" => "sqlite:" . $file],
                $this->settings["tools"],
            ),
            $paths,
        );
        $this->files = new LocalBackupFiles($paths, $this->root . "/work");
        $this->maintenance = new class implements MaintenanceMode {
            public function status(): MaintenanceState
            {
                return MaintenanceState::down(
                    300,
                    new \DateTimeImmutable("2026-11-27T00:00:00Z"),
                );
            }
            public function down(int $retryAfter = 300): MaintenanceState
            {
                return $this->status();
            }
            public function up(): MaintenanceState
            {
                return MaintenanceState::up();
            }
        };
        $this->service = new BackupService(
            $this->engine,
            $this->files,
            new LazyBackupHistory(fn() => $this->anchors, $this->storage),
            $this->maintenance,
        );
    }

    public function saveSettings(): void
    {
        file_put_contents(
            $this->configurationFile,
            json_encode($this->settings, JSON_THROW_ON_ERROR),
        );
        chmod($this->configurationFile, 0600);
    }

    public function dispose(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->root);
    }
}
