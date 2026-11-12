<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleInstanceReader;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Throwable;

/** Operator diagnostic only; this does not save or approve an instance binding. */
final class MoodleInstanceCommand extends ConsoleCommand
{
    protected string $name = "sis:moodle:instance";
    protected string $description = "Verify the optional Moodle plugin and display its installation fingerprint.";

    public function __construct(Application $codefy, private readonly MoodleInstanceReader $reader)
    {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        try {
            if (!$this->reader->enabled()) {
                $this->terminalRaw("Moodle integration is disabled. No installation identity was requested.");
                return self::SUCCESS;
            }
            $identity = $this->reader->identify();
        } catch (MoodleConnectionUnavailableException $error) {
            $this->terminalError($error->getMessage());
            return self::FAILURE;
        } catch (Throwable) {
            $this->terminalError(
                "Moodle instance verification failed. Review the plugin, service scope and configuration.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw("Installation fingerprint (SHA-256): " . $identity->instanceFingerprint());
        $this->terminalRaw("No instance binding, identity link or synchronization was created.");
        return self::SUCCESS;
    }
}
