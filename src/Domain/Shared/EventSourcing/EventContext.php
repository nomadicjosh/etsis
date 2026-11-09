<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Construct inside an authorized command, never directly from an HTTP actor/time payload. */
final readonly class EventContext implements \JsonSerializable
{
    public function __construct(
        public ?EntityId $actor,
        public ?DateTimeImmutable $effectiveAt,
        public ?EntityId $correlation,
        public ?EntityId $causation = null,
        #[\SensitiveParameter] public ?string $reason = null,
        public ?EntityId $approval = null,
        public string $origin = "command",
        public ?string $sourceTransactionId = null,
        /** @var array<string, mixed>|null */
        #[\SensitiveParameter] public ?array $legacyRecord = null,
    ) {
        if (
            !in_array($origin, ["command", "framework", "legacy"], true) ||
            ($origin === "command" &&
                ($actor === null ||
                    $effectiveAt === null ||
                    $correlation === null)) ||
            ($origin !== "legacy" && $legacyRecord !== null) ||
            ($sourceTransactionId !== null &&
                preg_match('/^[a-f0-9-]{36}$/Di', $sourceTransactionId) !== 1)
        ) {
            throw new InvalidArgumentException(
                "A valid event context and provenance are required.",
            );
        }
        if (
            $reason !== null &&
            (!mb_check_encoding($reason, "UTF-8") ||
                trim($reason) === "" ||
                mb_strlen($reason) > 10000 ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason))
        ) {
            throw new InvalidArgumentException(
                "A valid protected event reason is required.",
            );
        }
    }

    /** @return array{actor_id: ?string, effective_at: ?string, effective_timezone: ?string, correlation_id: ?string, causation_id: ?string, reason: ?string, approval_id: ?string, origin: string, source_transaction_id: ?string, legacy_record: ?array<string, mixed>} */
    public function describe(): array
    {
        return [
            "actor_id" => $this->actor?->value,
            "effective_at" => $this->effectiveAt
                ?->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z"),
            "effective_timezone" => $this->effectiveAt
                ?->getTimezone()
                ->getName(),
            "correlation_id" => $this->correlation?->value,
            "causation_id" => $this->causation?->value,
            "reason" => $this->reason,
            "approval_id" => $this->approval?->value,
            "origin" => $this->origin,
            "source_transaction_id" => $this->sourceTransactionId,
            "legacy_record" => $this->legacyRecord,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ["context" => "[protected historical context]"];
    }
    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
