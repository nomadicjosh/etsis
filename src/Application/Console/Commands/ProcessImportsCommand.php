<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Import\Service\ImportWorker;
use Symfony\Component\Console\Input\InputOption;

final class ProcessImportsCommand extends ConsoleCommand
{
    protected string $name = "sis:imports:run";
    protected string $description = "Process bounded server-side import batches and create private completion notices.";

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            "limit",
            null,
            InputOption::VALUE_REQUIRED,
            "Maximum batches (1–20).",
            "2",
        );
    }
    public function handle(): int
    {
        $raw = $this->getOptions("limit");
        if (!is_string($raw) || !preg_match('/^(?:[1-9]|1[0-9]|20)$/D', $raw)) {
            $this->terminalError("Use a limit from 1 through 20.");
            return self::INVALID;
        }
        $counts = [];
        $deadline = microtime(true) + 20;
        try {
            $worker = $this->codefy->make(ImportWorker::class);
            for ($i = 0; $i < (int) $raw && microtime(true) < $deadline; $i++) {
                $status = $worker->runOne();
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if ($status === "idle") {
                    break;
                }
            }
        } catch (\Throwable) {
            $this->terminalError(
                "Import processing stopped. Check the server worker and private import activity.",
            );
            return self::FAILURE;
        }
        foreach ($counts as $status => $count) {
            $this->terminalRaw($status . ": " . $count);
        }
        return self::SUCCESS;
    }
}
