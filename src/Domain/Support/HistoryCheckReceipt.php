<?php

declare(strict_types=1);

namespace Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use JsonSerializable;

/** Operational observation receipt; never a projection checkpoint or an approval. */
final readonly class HistoryCheckReceipt implements JsonSerializable
{
    public const int EVENT_LIMIT = 200;
    public const array STATUSES = [
        "verified",
        "history_gaps",
        "observed_empty",
        "unverified",
        "changed",
        "limited",
    ];

    public function __construct(
        public EntityId $id,
        public HistoryCheckScope $scope,
        public EntityId $actor,
        public string $status,
        public int $events,
        public string $checkedAt,
    ) {
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s\Z",
            $checkedAt,
            new DateTimeZone("UTC"),
        );
        if (
            !in_array($status, self::STATUSES, true) ||
            $events < 0 ||
            $events > self::EVENT_LIMIT ||
            $date === false ||
            $date->format("Y-m-d\TH:i:s\Z") !== $checkedAt
        ) {
            throw new InvalidArgumentException("Use a bounded, valid history-check receipt.");
        }
    }

    public function view(): array
    {
        return [
            "reference" => $this->id->value,
            "status" => $this->status,
            "events_checked" => $this->events,
            "checked_at" => $this->checkedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->view();
    }

    public function __debugInfo(): array
    {
        return ["history_check" => "[restricted support receipt]"];
    }
}
