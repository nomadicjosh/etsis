<?php

declare(strict_types=1);

namespace Application\Console;

use Codefy\Framework\Console\ConsoleKernel;
use Codefy\Framework\Scheduler\Schedule;
use Application\Console\Commands\MaintenanceCommand;
use Application\Provider\MaintenanceServiceProvider;
use Qubus\Exception\Data\TypeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Codefy\Framework\Console\ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class Kernel extends ConsoleKernel
{
    /**
     * @throws Throwable
     */
    public function handle(InputInterface $input, ?OutputInterface $output = null): int
    {
        $mode = MaintenanceServiceProvider::mode($this->codefy);
        if ($input->getFirstArgument() === 'sis:maintenance') {
            // Recovery controls must work even when ordinary providers or the database cannot boot.
            $console = new ConsoleApplication($this->codefy);
            $console->setAutoExit(false);
            $console->addCommand(new MaintenanceCommand($this->codefy, $mode));
            return $console->run($input, $output);
        }
        if ($mode->status()->paused() && !MaintenanceCommandPolicy::permits($input)) {
            ($output ?? new ConsoleOutput())->writeln(
                'Maintenance is active or unavailable. No application work was started; retry after reopening.',
            );
            return MaintenanceCommandPolicy::PAUSED;
        }
        return parent::handle($input, $output);
    }

    public function call(string $command, array $parameters = [], bool|OutputInterface|null $outputBuffer = null): int
    {
        if (
            MaintenanceServiceProvider::mode($this->codefy)->status()->paused()
            && !MaintenanceCommandPolicy::permits(new StringInput($command))
        ) {
            if ($outputBuffer instanceof OutputInterface) {
                $outputBuffer->writeln('Maintenance is active or unavailable. No application work was started.');
            }
            return MaintenanceCommandPolicy::PAUSED;
        }
        return parent::call($command, $parameters, $outputBuffer);
    }

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
     * @throws TypeException
     */
    protected function schedule(Schedule $schedule): void
    {
        if ($this->codefy->configContainer->getConfigKey('sis.mail_enabled', false) === true) {
            $schedule->command('sis:accounts:send-setup --apply')->everyMinute()->onlyOneInstance();
            $schedule->command('sis:accounts:email-verification --send --apply --limit=10')
                ->everyMinute()->onlyOneInstance();
            $schedule->command('sis:communications:send --apply --limit=10')->everyMinute()->onlyOneInstance();
        }
        $schedule->command('sis:admissions:documents --apply --limit=1')->everyMinute()->onlyOneInstance();
        $schedule->command('sis:reports:prune --apply --limit=50')->hourly()->onlyOneInstance();
        $schedule->command('sis:accounts:prune-setup --apply --limit=50')->hourly()->onlyOneInstance();
        $schedule->command('sis:accounts:email-verification --prune --apply --limit=50')->hourly()->onlyOneInstance();
        $schedule->command('sis:website-forms:subscribe --apply --limit=1')->everyMinute()->onlyOneInstance();
        $schedule->command('sis:website-forms:prune --apply --limit=100')->hourly()->onlyOneInstance();
        $schedule->command('sis:reports:export --apply --limit=1')->everyMinute()->onlyOneInstance();
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
