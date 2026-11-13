<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleSectionSyncService;
use Symfony\Component\Console\Input\InputOption;

final class MoodleSyncCommand extends ConsoleCommand
{
    protected string $name = "sis:moodle:sync";
    protected string $description = "Reconcile bounded student/instructor access for approved Moodle section links.";
    public function __construct(Application $codefy)
    {
        parent::__construct($codefy);
    }
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            "limit",
            null,
            InputOption::VALUE_REQUIRED,
            "Maximum membership steps (1–20).",
            "10",
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
        $deadline = microtime(true) + 30;
        try {
            $service = $this->codefy->make(MoodleSectionSyncService::class);
            for ($i = 0; $i < (int) $raw && microtime(true) < $deadline; $i++) {
                $status = $service->runOne();
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if (in_array($status, ["disabled", "idle", "review_required"], true)) {
                    break;
                }
            }
        } catch (\Throwable) {
            $this->terminalError(
                "Synchronization stopped. Review institutional settings and retained section status.",
            );
            return self::FAILURE;
        }
        foreach ($counts as $status => $count) {
            $this->terminalRaw($status . ": " . $count);
        }
        return isset($counts["review_required"]) ? self::FAILURE : self::SUCCESS;
    }
}
