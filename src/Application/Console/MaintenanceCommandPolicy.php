<?php

declare(strict_types=1);

namespace Application\Console;

use Symfony\Component\Console\Input\InputInterface;

/** Exact names only: abbreviated application or queue commands cannot bypass a maintenance pause. */
final class MaintenanceCommandPolicy
{
    public const int PAUSED = 75;

    public static function permits(InputInterface $input): bool
    {
        if ($input->hasParameterOption(['--help', '-h'], true)) {
            return true;
        }
        return in_array($input->getFirstArgument() ?? 'list', [
            'sis:maintenance', 'sis:history:verify', 'sis:history:anchor', 'sis:access:bootstrap',
            'sis:database:postgres', 'sis:projections:account', 'sis:projections:academic-dates',
            'list', 'help', 'route:list', 'schedule:list',
            'migrate', 'migrate:check', 'migrate:status', 'migrate:up', 'migrate:down', 'migrate:rollback',
        ], true);
    }
}
