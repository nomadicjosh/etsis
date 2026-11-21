<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\AcademicRecord\Repository\AcademicProjectionVerifier;
use Domain\Shared\ValueObject\EntityId;
use Domain\Finance\Repository\AccountProjectionVerifier;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\HistoricalIntegrityVerifier;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Trusted deployment operators only; deliberately lazy to keep console registration independent of storage. */
final class VerifyHistoryCommand extends ConsoleCommand
{
    protected string $name = "sis:history:verify";
    protected string $description = "Verify protected chains, references and retry receipts in read-only batches.";
    protected array $options = [
        ["streams", null, "required", "Maximum stream chains visited (1–50).", "10"],
        ["events", null, "required", "Maximum global checks or selected-source replay events (1–2000).", "500"],
        ["cursor", null, "required", "Protected continuation from an earlier verification batch."],
        ["json", null, "none", "Emit machine-readable counts and a protected continuation cursor."],
        ['student', null, 'required', 'Check one student’s available academic sources and projections; no cursor.'],
        ['account', null, 'required', 'Verify one financial account and its balance/posting projections; no cursor.'],
    ];
    public function __construct(Application $codefy)
    {
        parent::__construct($codefy);
    }
    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption("streams")->setDefault("10");
        $this->getDefinition()->getOption("events")->setDefault("500");
    }
    public function handle(): int
    {
        $json = $this->getOptions("json") === true;
        try {
            $streams = $this->number($this->getOptions("streams"), 50);
            $events = $this->number($this->getOptions("events"), 2000);
            $cursor = $this->getOptions("cursor");
            if ($cursor !== null && (!is_string($cursor) || $cursor === "" || strlen($cursor) > 32768)) {
                throw new InvalidArgumentException();
            }
            $account = $this->getOptions('account');
            $student = $this->getOptions('student');
            if ($student !== null) {
                if (
                    !is_string($student) || $account !== null || $cursor !== null ||
                    $this->input->hasParameterOption('--streams')
                ) {
                    throw new InvalidArgumentException();
                }
                return $this->student(new EntityId($student), $events, $json);
            }
            if ($account !== null) {
                if (!is_string($account) || $cursor !== null || $this->input->hasParameterOption('--streams')) {
                    throw new InvalidArgumentException();
                }
                return $this->account(new AccountId($account), $events, $json);
            }
            $result = $this->codefy
                ->make(HistoricalIntegrityVerifier::class)
                ->verify(cursor: $cursor, streamLimit: $streams, eventLimit: $events);
        } catch (InvalidArgumentException) {
            return $this->failure(
                $json,
                "invalid_input",
                "Use --streams=1..50, --events=1..2000 and a valid optional verification cursor. "
                    . 'Use --account=ULID or --student=ULID with --events and optional --json for a selected check.',
                self::INVALID,
            );
        } catch (HistoryVerificationFailedException $exception) {
            return $this->failure(
                $json,
                "stream_unverified",
                "Recorded history could not be verified. No records were changed.",
                self::FAILURE,
                $exception->streamReference?->value,
            );
        } catch (Throwable) {
            return $this->failure(
                $json,
                "unavailable",
                "History verification is unavailable. Check protected storage and key configuration; " .
                    "no records were changed.",
                self::FAILURE,
            );
        }
        if ($json) {
            $this->output->writeln(
                json_encode($result->describe(), JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->terminalRaw(
                "Read-only check: " .
                    $result->streams .
                    " streams completed; " .
                    $result->events .
                    " events authenticated; " .
                    $result->inventoryEvents .
                    " event references checked; " .
                    $result->receipts .
                    " receipts and " .
                    $result->receiptEvents .
                    " associated events verified in this batch.",
            );
            $this->terminalRaw(
                $result->complete
                    ? "Chain, reference and receipt traversals finished for the rows visible to this pass."
                    : "More history remains. Use --json for the protected continuation cursor; rerun with --cursor.",
            );
            $this->terminalRaw(
                "This checks stored history; it does not certify projections, " .
                    "archive completeness or restore readiness.",
            );
        }
        return $result->complete ? self::SUCCESS : 3;
    }

    private function student(EntityId $student, int $events, bool $json): int
    {
        $result = $this->codefy->make(AcademicProjectionVerifier::class)->verify($student, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Student academic check ' . $student->value . ': ' . $result->status . '.');
            $this->terminalRaw($result->events . ' events checked; '
                . ($result->records ?? 'unknown') . ' current records.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. Earlier gaps and an empty observation do not establish '
                . 'archive completeness. Changed or limited checks must be repeated in a controlled review. '
                . 'This does not evaluate degree completion or verify pursuit attribution and finance.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited', 'history_gaps', 'observed_empty' => 3,
            default => self::FAILURE,
        };
    }

    private function account(AccountId $account, int $events, bool $json): int
    {
        $result = $this->codefy->make(AccountProjectionVerifier::class)->verify($account, $events);
        if ($json) {
            $this->output->writeln(json_encode($result->describe(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw('Financial account ' . $result->account->value . ': ' . $result->status . '.');
            $this->terminalRaw('Captured position: ' . ($result->position ?? 'unknown') . '; '
                . $result->events . ' events checked; ' . ($result->postings ?? 'unknown') . ' expected postings.');
            if ($result->issues !== []) {
                $this->terminalRaw('Unverified components: ' . implode(', ', $result->issues) . '.');
            }
            $this->terminalRaw('No records were changed. A changed or limited result is not a completed check. '
                . 'This checks one account and its projections, not external settlement or every retry receipt.');
        }
        return match ($result->status) {
            'verified' => self::SUCCESS,
            'changed', 'limited' => 3,
            default => self::FAILURE,
        };
    }
    private function number(mixed $value, int $maximum): int
    {
        if (
            !is_string($value) ||
            preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 ||
            (int) $value > $maximum
        ) {
            throw new InvalidArgumentException();
        }
        return (int) $value;
    }
    private function failure(
        bool $json,
        string $error,
        string $message,
        int $code,
        ?string $reference = null,
    ): int {
        if ($json) {
            $this->output->writeln(
                json_encode(
                    [
                        "status" => "failed",
                        "error" => $error,
                        "message" => $message,
                        "stream_reference" => $reference,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->terminalError(
                $message . ($reference === null ? "" : " Stream support reference: " . $reference),
            );
        }
        return $code;
    }
}
