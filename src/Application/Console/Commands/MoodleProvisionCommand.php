<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleProvisioningWorker;
use Throwable;

final class MoodleProvisionCommand extends ConsoleCommand
{
    protected string $name = "sis:moodle:provision";
    protected string $description = "Process at most one reviewed Moodle account request.";
    public function __construct(Application $codefy, private readonly MoodleProvisioningWorker $worker)
    {
        parent::__construct($codefy);
    }
    public function handle(): int
    {
        try {
            $status = $this->worker->runOne();
        } catch (Throwable) {
            $this->terminalError(
                "Provisioning could not finish. Retain the saved request and review its local status.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw(
            match ($status) {
                "disabled" => "Moodle provisioning is disabled. No account request was processed.",
                "idle" => "No eligible Moodle account request is waiting.",
                "completed" => "One Moodle account request completed; its person link was retained.",
                "review_required"
                    => "One Moodle account request requires staff review. Check the person workspace.",
                default
                    => "The outcome is uncertain. A later run will reconcile it after the lease expires.",
            },
        );
        return in_array($status, ["review_required", "retry_pending"], true) ? self::FAILURE : self::SUCCESS;
    }
}
