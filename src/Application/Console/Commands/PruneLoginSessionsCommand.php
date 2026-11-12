<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\User\Service\SessionRetention;
use InvalidArgumentException;
use Throwable;

/** Deployment operator access only. Never expose as a browser/API operation. */
final class PruneLoginSessionsCommand extends ConsoleCommand
{
    protected string $name = "sis:auth:prune-sessions";
    protected string $description = "Preview or prune a bounded batch of expired sign-in and Moodle launch references.";
    protected array $options = [
        ["apply", null, "none", "Remove eligible references; otherwise preview only."],
        ["limit", null, "required", "Maximum candidates examined per support table (1–500).", "100"],
        [
            "grace-hours",
            null,
            "required",
            "Retain references this many hours beyond their expiry (1–8760).",
            "24",
        ],
    ];
    public function __construct(Application $codefy)
    {
        parent::__construct($codefy);
    }
    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption("limit")->setDefault("100");
        $this->getDefinition()->getOption("grace-hours")->setDefault("24");
    }
    public function handle(): int
    {
        try {
            $limit = $this->number($this->getOptions("limit"), 500);
            $grace = $this->number($this->getOptions("grace-hours"), 8760);
        } catch (InvalidArgumentException) {
            $this->terminalError("Use --limit=1..500 and --grace-hours=1..8760.");
            return self::INVALID;
        }
        try {
            // Resolve storage only for an explicit valid invocation, not while registering console commands.
            $result = $this->codefy
                ->make(SessionRetention::class)
                ->run(apply: $this->getOptions("apply") === true, limit: $limit, graceHours: $grace);
        } catch (Throwable) {
            $this->terminalError(
                "Session cleanup could not finish. Check the sign-in support migrations and database availability.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw(
            ($result->applied ? "Applied" : "Preview only; no records changed") .
                ". Expiry cutoff: " .
                $result->cutoff->format("Y-m-d\TH:i:s\Z") .
                ".",
        );
        $this->terminalRaw(
            "Moodle launch references " .
                ($result->applied ? "removed" : "eligible") .
                ": " .
                $result->launches .
                ".",
        );
        $this->terminalRaw(
            "Sign-in references " .
                ($result->applied ? "removed" : "eligible") .
                ": " .
                $result->sessions .
                ".",
        );
        $this->terminalRaw(
            "Retained for launch references: " .
                $result->referenced .
                "; invalid metadata: " .
                $result->invalid .
                "; changed concurrently: " .
                $result->changed .
                ".",
        );
        if ($result->batchFull) {
            $this->terminalRaw(
                "Candidate limit reached; more rows may remain. Review the counts before another bounded run.",
            );
        }
        if ($result->invalid > 0) {
            $this->terminalRaw("Invalid expiry/reference metadata was retained for operator review.");
        }
        return self::SUCCESS;
    }
    private function number(mixed $value, int $maximum): int
    {
        if (
            !is_string($value) ||
            preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 ||
            (int) $value > $maximum
        ) {
            throw new InvalidArgumentException("Use bounded positive integers.");
        }
        return (int) $value;
    }
}
