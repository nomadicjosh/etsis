<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Shared\EventSourcing\HistoricalIntegrityVerifier;
use Domain\Shared\EventSourcing\HistoryVerificationFailedException;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Trusted deployment operators only; deliberately lazy to keep console registration independent of storage. */
final class VerifyHistoryCommand extends ConsoleCommand
{
    protected string $name = "sis:history:verify";
    protected string $description = "Verify a bounded batch of protected event chains without changing records.";
    protected array $options = [
        ["streams", null, "required", "Maximum stream chains visited (1–50).", "10"],
        ["events", null, "required", "Maximum combined chain/inventory event rows checked (1–2000).", "500"],
        ["cursor", null, "required", "Protected continuation from an earlier verification batch."],
        ["json", null, "none", "Emit machine-readable counts and a protected continuation cursor."],
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
            $result = $this->codefy
                ->make(HistoricalIntegrityVerifier::class)
                ->verify(cursor: $cursor, streamLimit: $streams, eventLimit: $events);
        } catch (InvalidArgumentException) {
            return $this->failure(
                $json,
                "invalid_input",
                "Use --streams=1..50, --events=1..2000 and a valid optional verification cursor.",
                self::INVALID,
            );
        } catch (HistoryVerificationFailedException $exception) {
            return $this->failure(
                $json,
                "stream_unverified",
                "Recorded stream could not be verified. No records were changed.",
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
                    " event references checked in this batch.",
            );
            $this->terminalRaw(
                $result->complete
                    ? "Stream-chain and event-reference traversals finished for the rows visible to this pass."
                    : "More history remains. Use --json for the protected continuation cursor; rerun with --cursor.",
            );
            $this->terminalRaw(
                "This checks stored event chains; it does not certify projections, operation receipts, " .
                    "archive completeness or restore readiness.",
            );
        }
        return $result->complete ? self::SUCCESS : 3;
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
