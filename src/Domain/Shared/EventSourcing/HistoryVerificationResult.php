<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use InvalidArgumentException;
use JsonSerializable;

final readonly class HistoryVerificationResult implements JsonSerializable
{
    public function __construct(
        public bool $complete,
        public int $streams,
        public int $events,
        #[\SensitiveParameter] public ?string $nextCursor = null,
        public int $inventoryEvents = 0,
        public int $receipts = 0,
        public int $receiptEvents = 0,
    ) {
        if (
            $streams < 0 ||
            $events < 0 ||
            $inventoryEvents < 0 ||
            $receipts < 0 ||
            $receiptEvents < 0 ||
            $complete !== ($nextCursor === null)
        ) {
            throw new InvalidArgumentException(
                "Use consistent bounded verification counts and continuation state.",
            );
        }
    }
    /** Explicit operator output. The cursor contains protected traversal metadata, never event contents.
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            "status" => $this->complete ? "complete" : "limited",
            "streams_completed" => $this->streams,
            "events_checked" => $this->events,
            "inventory_rows_checked" => $this->inventoryEvents,
            "receipts_checked" => $this->receipts,
            "receipt_events_checked" => $this->receiptEvents,
            "next_cursor" => $this->nextCursor,
        ];
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["verification" => "[private verification continuation]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
