<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Request-local seat check, never persisted or accepted from HTTP. */
final readonly class WaitlistClaim implements \JsonSerializable
{
    public function __construct(
        public EntityId $section,
        public EntityId $student,
        public int $version,
        public ?string $entry,
        #[\SensitiveParameter] public HistoryReadBudget $budget,
        #[\SensitiveParameter] public ?WaitlistBypass $bypass = null,
    ) {
    }
    public function jsonSerialize(): array
    {
        return ["claim" => "[internal seat-priority check]"];
    }
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
