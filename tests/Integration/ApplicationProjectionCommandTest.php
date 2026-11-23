<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ApplicationProjectionCommandTest extends TestCase
{
    public function testApplicationVerificationUsesActualLazyProvidersAndPrivateConsoleResults(): void
    {
        $this->check('check-application-projections');
    }

    public function testApplicationSupportCheckUsesActualRoutesCsrfPrivacyAndSourceAuthority(): void
    {
        $this->check('check-support-history');
    }

    private function check(string $mode): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', $mode]);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
