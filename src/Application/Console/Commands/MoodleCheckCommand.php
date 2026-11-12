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
                "Moodle check failed. Verify endpoint, TLS trust, REST service and token settings.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw(
            "Moodle REST connection verified. Function discovery only; SSO and synchronization are not enabled.",
        );
        foreach ($capabilities->describe() as $name => $available) {
            $this->terminalRaw($name . ": " . ($available ? "available" : "not advertised"));
        }
        return self::SUCCESS;
    }
}
