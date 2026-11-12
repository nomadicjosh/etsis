<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConsoleBootstrapTest extends TestCase
{
    public function testDisabledMoodleCheckResolvesThroughActualProvidersWithoutDatabaseOrNetworkUse(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-moodle']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testWebhookCanResolveBeforeBootingTheHttpRouter(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-early-webhook']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testPortalRoutesCsrfAndNativeVihzhuoBlocksBootWithinBoundedMemory(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-portal']);
        $process->setTimeout(8);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testProfileUpdatesUseTheConfiguredEventStoreThroughTheCommandBus(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-profile-command']);
        $process->setTimeout(8);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCourseRouteResolvesDependenciesAndReturnsJson(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-course-route']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testMigrationStatusBootsWithinBoundedMemoryWithoutRunningMigrations(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'migrate:status']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('CreateSisWorkspaceTables', $process->getOutput());
        self::assertStringContainsString('| down', $process->getOutput());
        self::assertStringNotContainsString('| up', $process->getOutput());
    }

    public function testAuthenticationAliasResolvesWithDatabaseStorage(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-authentication']);
        $process->setTimeout(6);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
