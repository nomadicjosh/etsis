<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

final class BootstrapAccessCommand extends ConsoleCommand
{
    protected string $name = 'sis:access:bootstrap';
    protected string $description = 'Assign the first super administrator when no super administrator exists.';
    protected array $args = [['user_id', 'required', 'Existing user ULID']];

    public function __construct(Application $codefy, private readonly BootstrapAccess $bootstrap)
    {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        try {
            $id = $this->getArgument('user_id');
            if (!is_string($id)) {
                return self::INVALID;
            }
            $this->bootstrap->assignFirstAdministrator(new EntityId($id));
        } catch (Throwable) {
            $this->terminalError(
                'Bootstrap refused. Check the user, migrations and that no super administrator exists.',
            );

            return self::FAILURE;
        }
        $this->terminalRaw('Initial super administrator assigned.');

        return self::SUCCESS;
    }
}
