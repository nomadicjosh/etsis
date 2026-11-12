<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\AccountIdentityLookup;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use Throwable;

/** Deployment only. Interactive bootstrap never prints identity inputs or asks for a password. */
final class BootstrapAccessCommand extends ConsoleCommand
{
    protected string $name = "sis:access:bootstrap";
    protected string $description = "Assign the first super administrator when none exists.";
    protected array $args = [
        ["user_id", "optional", "Existing user ULID; omit to select by email interactively."],
    ];

    public function __construct(Application $codefy)
    {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        try {
            $value = $this->getArgument("user_id");
            if ($value === null && !$this->input->isInteractive()) {
                $this->terminalError(
                    "Provide an existing user ULID or run interactively after registering an account.",
                );
                return self::INVALID;
            }
            $user =
            $value === null ? $this->chooseAccount() : (is_string($value) ? new EntityId($value) : null);
            if ($user === null) {
                return self::INVALID;
            }
            // Resolve persistence only when explicitly executing a valid bootstrap request.
            $this->codefy->make(BootstrapAccess::class)->assignFirstAdministrator($user);
        } catch (Throwable) {
            $this->terminalError(
                "Bootstrap refused. Check the account, migrations and that no super administrator exists.",
            );
            return self::FAILURE;
        }
        $this->terminalRaw("Initial super administrator assigned. Sign in to etSIS and open /admin/.");
        return self::SUCCESS;
    }

    private function chooseAccount(): ?EntityId
    {
        $this->terminalRaw(
            "Register your own etSIS account first. This command grants the first super role only.",
        );
        $question = new Question("Existing account email (input hidden): ");
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setMaxAttempts(1);
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper("question");
        $email = $helper->ask($this->input, $this->output, $question);
        if (
            !is_string($email) ||
            strlen($email) > 191 ||
            filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->terminalError("Enter the valid email used when registering the account.");
            return null;
        }
        $user = $this->codefy->make(AccountIdentityLookup::class)->forEmail(trim($email));
        if ($user === null) {
            $this->terminalError(
                "No unique local account matched. Register the account first and try again.",
            );
            return null;
        }
        if ($this->confirm("Grant the first super administrator role to this existing account?") !== true) {
            $this->terminalRaw("Bootstrap cancelled. No role was changed.");
            return null;
        }
        return $user;
    }
}
