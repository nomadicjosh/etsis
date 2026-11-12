<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
final class AlphaInstallTest extends TestCase
{
    public function testExternalInstallFixturesRefuseAnOrdinaryDatabaseNameBeforeConnecting(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__) . '/Support/AlphaInstallSmoke.php'],
            env: ['ETSIS_TEST_INSTALL_DSN' => 'mysql:host=192.0.2.1;dbname=production']);
        $process->setTimeout(5);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('empty temporary database', $process->getErrorOutput());
        self::assertStringNotContainsString('192.0.2.1', $process->getErrorOutput());
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
