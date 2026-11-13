<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleConnectionProbe;
use Throwable;

/** Deployment operator access only. Never expose this command as a browser route. */
final class MoodleCheckCommand extends ConsoleCommand
{
    protected string $name = 'sis:moodle:check';
    protected string $description = 'Check the configured Moodle REST service without synchronizing records.';

    public function __construct(Application $codefy, private readonly MoodleConnectionProbe $probe)
    {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        try {
            if (!$this->probe->enabled()) {
                $this->terminalRaw("Moodle connection check is disabled.");
                return self::SUCCESS;
            }
            $capabilities = $this->probe->check();
        } catch (Throwable) {
            $this->terminalError(
                "Moodle check failed. Verify the configured site URL, TLS trust, REST service and token settings.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw(
            "Moodle site URL and REST connection verified. This check does not change SSO or synchronization settings.",
        );
        foreach ($capabilities->describe() as $name => $available) {
            $this->terminalRaw($name . ": " . ($available ? "available" : "not advertised"));
        }
        $this->terminalRaw(
            "The section synchronization service uses connector-managed enrollment. Generic course creation/update,"
            . " manual enrollment/unenrollment and grade-item functions are not required by this connector.",
        );
        return self::SUCCESS;
    }
}
