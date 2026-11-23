<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MaintenanceModeTest extends TestCase
{
    public function testOperatorRecoveryAndActualSuperOnlySignInPreserveSessionAndCsrfBoundaries(): void
    {
        $process = new Process([
            PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-maintenance',
        ]);
        $process->setTimeout(18);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testFrontControllerPausesWebhooksBeforeBootAndKeepsFailuresPrivate(): void
    {
        foreach (['html', 'json', 'head', 'webhook', 'cookie', 'super', 'regular', 'expired'] as $case) {
            $process = new Process([
                PHP_BINARY, '-d', 'memory_limit=96M',
                dirname(__DIR__) . '/Support/MaintenanceFrontController.php', $case,
            ]);
            $process->setTimeout(6);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $case . ': ' . $process->getErrorOutput());
            $metadata = json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($case === 'super' ? 200 : 503, $metadata['status'], $case);
            if (in_array($case, ['html', 'json', 'head', 'webhook'], true)) {
                self::assertFalse($metadata['booted'], $case);
            }
            self::assertStringNotContainsString('fixture-database-must-not-open', $process->getOutput());
            self::assertStringNotContainsString('fictional-untrusted-cookie', $process->getOutput());
            self::assertStringNotContainsString('Stack trace', $process->getOutput());
            if ($case === 'super') {
                self::assertStringContainsString('Campus workspace', $process->getOutput());
            } elseif ($case === 'head') {
                self::assertSame('', $process->getOutput());
            } elseif (in_array($case, ['json', 'webhook'], true)) {
                $json = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(['status', 'error', 'retry_after'], array_keys($json));
                self::assertSame('maintenance', $json['status']);
                self::assertSame(120, $json['retry_after']);
            } else {
                self::assertStringContainsString('href="/staff-access/"', $process->getOutput());
            }
        }
    }
}
