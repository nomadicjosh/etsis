<?php

declare(strict_types=1);

namespace Tests\Unit\Lms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MoodleGradeConnectorTest extends TestCase
{
    public static function outcomes(): iterable
    {
        foreach (
            [
                "available",
                "hidden",
                "ungraded",
                "missing_item",
                "scale",
                "stale",
                "denied",
                "disabled",
                "not_enrolled",
                "suspended",
            ] as $state
        ) {
            yield $state => [$state];
        }
    }

    #[DataProvider("outcomes")]
    public function testBoundedExportUsesNoWritesAndWithholdsUnreleasedValues(
        string $state,
    ): void {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=48M",
            dirname(__DIR__, 2) . "/Support/MoodleGradeConnectorSandbox.php",
            $state,
        ]);
        $process->setTimeout(5);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput() . $process->getOutput(),
        );
    }
}
