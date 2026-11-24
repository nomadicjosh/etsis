<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AlphaInstallTest extends TestCase
{
    public function testExternalInstallFixturesRefuseAnOrdinaryDatabaseNameBeforeConnecting(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'zend.exception_ignore_args=1',
            dirname(__DIR__) . '/Support/AlphaInstallSmoke.php', 'native']);
        $process->setInput(json_encode(['dsn' => 'pgsql:host=192.0.2.1;dbname=production',
            'username' => 'FICTIONAL_PRIVATE_USER', 'password' => 'FICTIONAL_PRIVATE_PASSWORD'], JSON_THROW_ON_ERROR));
        $process->setTimeout(5);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('owned empty temporary database', $process->getErrorOutput());
        foreach (['192.0.2.1', 'FICTIONAL_PRIVATE_USER', 'FICTIONAL_PRIVATE_PASSWORD'] as $private) {
            self::assertStringNotContainsString($private, $process->getErrorOutput() . $process->getOutput());
        }
    }

    public function testNativeFreshMigrationsRegistrationAndAdministratorSetup(): void
    {
        if (getenv('ETSIS_WORKFLOW_NATIVE_QUALIFICATION') !== '1') {
            self::markTestSkipped('Native installation qualification requires explicit opt-in.');
        }
        require_once dirname(__DIR__) . '/Support/NativeQualificationDatabase.php';
        $directory = sys_get_temp_dir() . '/etsis-native-install-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot prepare the private installation lease directory.');
        }
        $native = null;
        try {
            $native = new \Tests\Support\NativeQualificationDatabase($directory);
            $connection = $native->connection();
            $connection->pdo->exec('CREATE TABLE fictional_existing (id INTEGER PRIMARY KEY)');
            $connection->pdo->exec('INSERT INTO fictional_existing VALUES (7)');
            $refused = new Process([PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'zend.exception_ignore_args=1',
                dirname(__DIR__) . '/Support/AlphaInstallSmoke.php', 'native']);
            $refused->setInput(json_encode($native->workerSettings(), JSON_THROW_ON_ERROR));
            $refused->setTimeout(5);
            $refused->run();
            self::assertNotSame(0, $refused->getExitCode());
            self::assertSame(7, (int) $connection->pdo->query('SELECT id FROM fictional_existing')->fetchColumn());
            self::assertNotContains('migration', $connection->listTables());
            $connection->pdo->exec('DROP TABLE fictional_existing');
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'zend.exception_ignore_args=1',
                dirname(__DIR__) . '/Support/AlphaInstallSmoke.php', 'native']);
            $process->setInput(json_encode($native->workerSettings(), JSON_THROW_ON_ERROR));
            $process->setTimeout(60);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('initial administrator access passed', $process->getOutput());
            $count = (int) $connection->pdo->query('SELECT COUNT(*) FROM migration')->fetchColumn();
            self::assertSame(count(glob(dirname(__DIR__, 2) . '/database/migrations/[0-9]*_*.php')), $count);
            self::assertNotContains('event_store', $connection->listTables());
            if ($connection->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                self::assertSame(0, (int) $connection->pdo->query(
                    "SELECT COUNT(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace " .
                    "WHERE n.nspname='public' AND NOT c.convalidated"
                )->fetchColumn());
            }
        } finally {
            $native?->close();
            if (is_dir($directory) && count(scandir($directory)) === 2) {
                rmdir($directory);
            }
        }
    }

    public function testFreshMigrationsRegistrationAndInteractiveAdministratorSetup(): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=128M",
            dirname(__DIR__) . "/Support/AlphaInstallSmoke.php",
        ]);
        $process->setTimeout(60);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("initial administrator access passed", $process->getOutput());
    }
}
