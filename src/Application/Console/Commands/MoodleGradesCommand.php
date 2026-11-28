<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleGradeImportWorker;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

final class MoodleGradesCommand extends ConsoleCommand
{
    protected string $name = "sis:moodle:grades";
    protected string $description = "Collect bounded Moodle course-total candidates without publishing SIS grades.";

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
            "Maximum worker steps (1-20).",
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
        $counts = [];
        $deadline = microtime(true) + 30;
        try {
            $worker = $this->codefy->make(MoodleGradeImportWorker::class);
            for ($i = 0; $i < (int) $raw && microtime(true) < $deadline; $i++) {
                $status = $worker->runOne();
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if (
                    in_array(
                        $status,
                        ["disabled", "idle", "review_required"],
                        true,
                    )
                ) {
                    break;
                }
            }
        } catch (Throwable) {
            $this->terminalError(
                "Moodle grade intake stopped. Review its retained status and institutional settings.",
            );
            return self::FAILURE;
        }
        foreach ($counts as $status => $count) {
            $this->terminalRaw($status . ": " . $count);
        }
        return isset($counts["review_required"])
        ? self::FAILURE
        : self::SUCCESS;
    }
}
