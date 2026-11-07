<?php

declare(strict_types=1);

namespace Application\Console;

use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\ValueObject\EntityId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BootstrapAccessCommand extends Command
{
    public function __construct(private readonly BootstrapAccess $bootstrap)
    {
        parent::__construct('sis:access:bootstrap');
    }

    protected function configure(): void
    {
        $this->setDescription('Assign the first super administrator when no super administrator exists.')
            ->addArgument('user_id', InputArgument::REQUIRED, 'Existing user ULID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = $input->getArgument('user_id');
            if (!is_string($id)) {
                return self::INVALID;
            }
            $this->bootstrap->assignFirstAdministrator(new EntityId($id));
        } catch (Throwable) {
            $output->writeln(
                '<error>Bootstrap refused. Check the user, migrations and that no super administrator exists.</error>',
            );

            return self::FAILURE;
        }
        $output->writeln('Initial super administrator assigned.');

        return self::SUCCESS;
    }
}
