<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PersonPhotoRoutesTest extends TestCase
{
    public function testCleanupCommandUsesBoundedArgumentsAndARequiredOperator(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'sis:photos:purge --limit=0']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('Use a limit from 1 through 20.', $process->getOutput());
    }

    public function testActualProvidersOwnerAndStaffScopesPrivateContentAndCsrf(): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=96M",
            dirname(__DIR__) . "/Support/ConsoleStatusSmoke.php",
            "check-person-photos",
        ]);
        $process->setTimeout(25);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getOutput() . $process->getErrorOutput(),
        );
    }
}
