<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Access\Repository\AccessRepository;
use Domain\Person\Service\PersonPhotoService;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\LoginAccountStateReader;
use Infrastructure\Import\ImportCommandAccess;
use Infrastructure\Person\ZipPhotoArchive;
use Symfony\Component\Console\Input\InputOption;

final class PurgePersonPhotosCommand extends ConsoleCommand
{
    protected string $name = "sis:photos:purge";
    protected string $description = "Remove expired photo artifacts under a currently authorized operator.";

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            "actor",
            null,
            InputOption::VALUE_REQUIRED,
            "Enabled operator account ULID.",
        );
        $this->addOption(
            "limit",
            null,
            InputOption::VALUE_REQUIRED,
            "Maximum artifacts (1–20).",
            "10",
        );
    }

    public function handle(): int
    {
        $raw = $this->getOptions("limit");
        if (
            !is_string($raw) ||
            preg_match('/^(?:[1-9]|1[0-9]|20)$/D', $raw) !== 1
        ) {
            $this->terminalError("Use a limit from 1 through 20.");
            return self::INVALID;
        }
        try {
            $actor = new EntityId((string) $this->getOptions("actor"));
            $access = new ImportCommandAccess(
                $actor,
                $this->codefy->make(AccessRepository::class),
                $this->codefy->make(LoginAccountStateReader::class),
            );
            $count = $this->codefy
                ->make(PersonPhotoService::class)
                ->forAccess($access)
                ->purge((int) $raw);
            $this->codefy->make(ZipPhotoArchive::class)->cleanScratch();
            $this->terminalRaw("Expired photo artifacts removed: " . $count);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->terminalError(
                "Photo retention stopped. Verify the enabled operator, photo authority and protected storage.",
            );
            return self::FAILURE;
        }
    }
}
