<?php

declare(strict_types=1);

namespace Application\Console;

use Codefy\Framework\Console\ConsoleKernel;
use Codefy\Framework\Scheduler\Schedule;
use Symfony\Component\Console\Command\SignalableCommandInterface;

final class Kernel extends ConsoleKernel
{
    /**
     * Add your custom console commands here.
     *
     * @var array<class-string<SignalableCommandInterface>|callable>
     */
    protected array $commands = [];

    /**
     * Place all your scheduled tasks here.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // Register existing scripts using absolute paths and explicit arguments.
        // onlyOneInstance() runs in the foreground and holds the lock until completion.
        // $schedule->command('queue:run')->everyMinute()->onlyOneInstance();
    }

    /**
     * Place all your commands here that need to be registered
     * to your application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load();
    }
}
