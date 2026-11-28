<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PaymentGatewayRoutesTest extends TestCase
{
    public function testRealProvidersKeepReviewedGatewaySetupPrivateAndCurrent(): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "memory_limit=96M",
            dirname(__DIR__) . "/Support/ConsoleStatusSmoke.php",
            "check-payment-gateways",
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
