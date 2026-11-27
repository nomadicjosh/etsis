<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TranscriptArtifactConsoleTest extends TestCase
{
    public function testActualLazyConsoleProvidersVerifyArtifactsWithoutBusinessWriters(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-transcript-artifacts']);
        $process->setTimeout(20);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }
}
