<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Domain\Shared\Operations\MaintenanceMode;
use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Throwable;

/** Deployment-operator controls only; not an authenticated browser or SIS business command. */
final class MaintenanceCommand extends ConsoleCommand
{
    protected string $name = 'sis:maintenance';
    protected string $description = 'Inspect or explicitly change deployment maintenance mode without database access.';
    protected array $args = [['action', 'optional', 'status, down or up.']];
    protected array $options = [
        ['retry-after', null, 'required', 'Down-mode retry interval (30–3600 seconds).'],
    ];

    public function __construct(Application $codefy, private readonly MaintenanceMode $maintenance)
    {
        parent::__construct($codefy);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getArgument('action')->setDefault('status');
    }

    public function handle(): int
    {
        $action = $this->getArgument('action');
        $retry = $this->getOptions('retry-after');
        if (
            !in_array($action, ['status', 'down', 'up'], true)
            || ($retry !== null && ($action !== 'down' || !is_string($retry)
                || preg_match('/^[1-9][0-9]{1,3}$/D', $retry) !== 1 || (int) $retry < 30 || (int) $retry > 3600))
        ) {
            $this->terminalRaw('Use status, up, or down with an optional --retry-after=30..3600.');
            return self::INVALID;
        }
        try {
            $state = match ($action) {
                'down' => $this->maintenance->down($retry === null ? 300 : (int) $retry),
                'up' => $this->maintenance->up(),
                default => $this->maintenance->status(),
            };
        } catch (Throwable) {
            $this->terminalRaw('Maintenance controls could not finish. Check private storage and retry.');
            return self::FAILURE;
        }
        $this->terminalRaw(match ($state->status) {
            'up' => 'Maintenance is off. New requests and command launches are allowed.',
            'down' => 'Maintenance is on. General access and background work are paused; '
                . 'super administrators may sign in.',
            default => 'Maintenance state is unavailable. General access and background work remain paused.',
        });
        if ($state->paused()) {
            $this->terminalRaw('Retry interval in seconds: ' . $state->retryAfter . '.');
        }
        return $state->status === 'unavailable' ? self::FAILURE : self::SUCCESS;
    }
}
