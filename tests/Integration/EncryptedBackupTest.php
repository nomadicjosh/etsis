<?php

declare(strict_types=1);

namespace Tests\Integration;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Domain\Shared\Backup\BackupBudget;
use Infrastructure\Backup\BackupConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\BackupFixture;

final class EncryptedBackupTest extends TestCase
{
    private BackupFixture $fixture;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/BackupFixture.php";
        $binary = getenv("ETSIS_TEST_RESTIC");
        if (!is_string($binary) || !is_executable($binary)) {
            self::markTestSkipped(
                "Set ETSIS_TEST_RESTIC to an explicitly installed test Restic executable.",
            );
        }
        $this->fixture = new BackupFixture($binary);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->dispose();
        }
    }

    public function testRealEncryptedRepositoryRestoresDatabaseKeysPrivateFilesAndMatchingOriginalHistory(): void
    {
        $f = $this->fixture;
        $original = hash_file("sha256", $f->root . "/source.sqlite");
        $f->engine->initialize($f->config->budget());
        $result = $f->service->capture(
            $f->config->plan,
            $f->config->budget(),
            true,
        );
        self::assertSame("captured", $result["status"]);
        self::assertCount(1, $f->engine->recent($f->config->budget()));
        self::assertCount(1, glob($f->root . "/checkpoints/*.anchor"));
        $manifest = $f->engine->read($result["snapshot"], $f->config->budget());
        self::assertSame(3, $manifest->data["anchor"]["inventory"]["events"]);
        self::assertCount(5, $manifest->data["inventory"]);
        $destination = $f->root . "/restore";
        self::assertSame(
            "restored",
            $f->service->restore(
                $result["snapshot"],
                $destination,
                $f->config->plan->scope,
                $f->config->budget(),
            )["status"],
        );
        self::assertFileExists($destination . "/verified.json");
        self::assertFileDoesNotExist($destination . "/incomplete.json");
        $prefix = $destination . "/files" . $f->project;
        $key = Key::loadFromAsciiSafeString(
            file_get_contents($prefix . "/.enc.key"),
        );
        self::assertSame(
            "Fictional confidential evidence.",
            Crypto::decrypt(
                file_get_contents($prefix . "/storage/app/evidence.enc"),
                $key,
            ),
        );
        self::assertSame(0, fileperms($destination . "/database.dump") & 0077);
        self::assertSame(0, fileperms($prefix . "/.env") & 0077);
        $restored = $f->root . "/verified.sqlite";
        $process = new Process(
            ["/usr/bin/sqlite3", $restored],
            input: fopen($destination . "/database.dump", "rb"),
            timeout: 10,
        );
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "Fictional SQLite restore failed.",
        );
        $source = new \PDO("sqlite:" . $f->root . "/source.sqlite");
        $copy = new \PDO("sqlite:" . $restored);
        $tables = $source
            ->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            )
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            self::assertSame(
                $source->query("SELECT * FROM " . $quoted)->fetchAll(),
                $copy->query("SELECT * FROM " . $quoted)->fetchAll(),
            );
        }
        self::assertSame(
            $original,
            hash_file("sha256", $f->root . "/source.sqlite"),
        );
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $f->root . "/repository",
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                self::assertStringNotContainsString(
                    "fictional-never-log-this",
                    file_get_contents($file->getPathname()),
                );
                self::assertStringNotContainsString(
                    "Fictional confidential evidence.",
                    file_get_contents($file->getPathname()),
                );
            }
        }
    }

    public function testMissingQuiescenceAndWrongScopeNeverCreateOrOverwriteRecoveryOutput(): void
    {
        $f = $this->fixture;
        try {
            $f->service->capture($f->config->plan, $f->config->budget(), false);
            self::fail("Capture accepted an unquiesced boundary.");
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($f->root . "/repository/config");
        }
        $f->engine->initialize($f->config->budget());
        $result = $f->service->capture(
            $f->config->plan,
            $f->config->budget(),
            true,
        );
        try {
            $f->service->restore(
                $result["snapshot"],
                $f->root . "/wrong",
                "another-campus",
                $f->config->budget(),
            );
            self::fail("Wrong installation scope was accepted.");
        } catch (\RuntimeException) {
            self::assertDirectoryDoesNotExist($f->root . "/wrong");
        }
        mkdir($f->root . "/existing", 0700);
        file_put_contents(
            $f->root . "/existing/keep",
            "Fictional original output.",
        );
        try {
            $f->service->restore(
                $result["snapshot"],
                $f->root . "/existing",
                "fictional-campus",
                $f->config->budget(),
            );
            self::fail("Existing output was accepted.");
        } catch (\RuntimeException) {
            self::assertSame(
                "Fictional original output.",
                file_get_contents($f->root . "/existing/keep"),
            );
        }
    }

    public function testRestoreConfigurationDoesNotRequireTheLostOriginalFilesOrExportTools(): void
    {
        $f = $this->fixture;
        $f->settings["tools"] = ["pgsql" => "/missing/export-tool"];
        $f->settings["includes"] = ["/missing/original-installation"];
        $f->settings["checkpoint_key"] = $f->root . "/operator/missing.key";
        $f->saveSettings();
        $config = BackupConfiguration::load($f->configurationFile, $f->project);
        self::assertSame("fictional-campus", $config->plan->scope);
        $this->expectException(\RuntimeException::class);
        BackupConfiguration::load(
            $f->configurationFile,
            $f->project,
            capture: true,
        );
    }

    public function testInventoryAndDatabaseBudgetsNeverProduceACompletedBundle(): void
    {
        $f = $this->fixture;
        $f->engine->initialize($f->config->budget());
        try {
            $f->service->capture(
                $f->config->plan,
                new BackupBudget(30, 10, 50),
                true,
            );
            self::fail("Tiny artifact budget was accepted.");
        } catch (\RuntimeException) {
            self::assertSame([], $f->engine->recent($f->config->budget()));
        }
        $this->expectException(\RuntimeException::class);
        $f->files->inventory(
            $f->config->plan->includes,
            new BackupBudget(30, 1000000, 1),
        );
    }

    public function testAProducerFailureCannotBecomeAnEmptySuccessfulDatabaseBackup(): void
    {
        $f = $this->fixture;
        $f->engine->initialize($f->config->budget());
        $engine = new \Infrastructure\Backup\ResticBackupEngine(
            $f->config,
            new \Infrastructure\Backup\ResticProcess($f->config),
            new \Infrastructure\Backup\NativeDatabaseSource(
                ["dsn" => "sqlite:" . $f->root . "/source.sqlite"],
                ["sqlite" => realpath("/usr/bin/false")],
            ),
            new \Infrastructure\Backup\PrivateBackupPaths(
                $f->project . "/public",
            ),
        );
        try {
            $engine->database(
                \Domain\Shared\ValueObject\EntityId::generate()->value,
                $f->config->budget(),
            );
            self::fail("A failed native producer was accepted.");
        } catch (\RuntimeException) {
            self::assertSame([], $engine->recent($f->config->budget()));
        }
    }
}
