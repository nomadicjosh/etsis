<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MoodleGradeImportRoutesTest extends TestCase
{
    public function testRealProvidersRetainPrivateCsrfBackgroundAndGradeDecisionBoundaries(): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=96M",
            dirname(__DIR__) . "/Support/ConsoleStatusSmoke.php",
            "check-moodle-grades",
        ]);
        $process->setTimeout(20);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput() . $process->getOutput(),
        );
    }
}
