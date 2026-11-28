<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BackupCommandTest extends TestCase
{
    public function testPrivateOperatorCommandsResolveWithoutTheLiveDatabaseEvenAfterOriginalFilesAreLost(): void
    {
        $binary = getenv("ETSIS_TEST_RESTIC");
        if (!is_string($binary) || !is_executable($binary)) {
            self::markTestSkipped(
                "Set ETSIS_TEST_RESTIC to the reviewed local test executable.",
            );
        }
        $process = new Process(
            [
                PHP_BINARY,
                "-d",
                "memory_limit=96M",
                dirname(__DIR__) . "/Support/ConsoleStatusSmoke.php",
                "check-backups",
            ],
            timeout: 35,
        );
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getOutput() . $process->getErrorOutput(),
        );
    }
}
