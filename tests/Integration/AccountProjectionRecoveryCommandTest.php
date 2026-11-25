<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AccountProjectionRecoveryCommandTest extends TestCase
{
    public function testActualLazyProvidersAndCommandsCompleteStageCheckApplyAndDiscardPrivately(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-account-recovery']);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }

    public function testMaintenancePermitsOnlyTheExactRecoveryCommandName(): void
    {
        $names = ['sis:projections:account' => true, 'sis:projections:acc' => false, 'sis:projections' => false];
        foreach ($names as $name => $allowed) {
            self::assertSame($allowed, \Application\Console\MaintenanceCommandPolicy::permits(
                new \Symfony\Component\Console\Input\StringInput($name)
            ));
        }
    }
}
