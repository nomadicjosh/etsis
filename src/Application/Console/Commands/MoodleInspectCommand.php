<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Lms\Service\MoodleRecordReader;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Throwable;

/** Deployment operator only. Never register as a browser route or a synchronization worker. */
final class MoodleInspectCommand extends ConsoleCommand
{
    protected string $name = "sis:moodle:inspect";
    protected string $description = "Inspect one explicit Moodle user or course ID without creating a link.";
    protected array $args = [
        ["resource", "required", "user or course"],
        ["remote_id", "required", "Explicit positive Moodle record ID (not an etSIS person or section ID)"],
    ];
    public function __construct(Application $codefy, private readonly MoodleRecordReader $reader)
    {
        parent::__construct($codefy);
    }
    public function handle(): int
    {
        try {
            $kind = $this->getArgument("resource");
            $id = $this->getArgument("remote_id");
            if (
                !is_string($kind) ||
                !is_string($id) ||
                ($kind = MoodleResourceKind::tryFrom($kind)) === null
            ) {
                $this->terminalError("Choose user or course and an explicit positive Moodle ID.");
                return self::INVALID;
            }
            $external = new MoodleExternalId($id);
        } catch (Throwable) {
            $this->terminalError("Choose user or course and an explicit positive Moodle ID.");
            return self::INVALID;
        }
        try {
            if (!$this->reader->enabled()) {
                $this->terminalRaw("Moodle integration is disabled. No record was requested.");
                return self::SUCCESS;
            }
            $result = $this->reader->inspect($kind, $external);
        } catch (Throwable) {
            $this->terminalError(
                "Moodle inspection failed. Verify site, token scope and lookup function availability.",
            );
            return self::FAILURE;
        }
        if (!$result->returned) {
            $this->terminalRaw(
                "No matching record was returned. It may be missing or outside the service account scope.",
            );
        } else {
            $this->terminalRaw(
                "Matching record returned. No identity link, access grant or synchronization was created.",
            );
            foreach ($result->describe() as $flag => $value) {
                $this->terminalRaw($flag . ": " . ($value === null ? "unknown" : ($value ? "yes" : "no")));
            }
        }
        return self::SUCCESS;
    }
}
