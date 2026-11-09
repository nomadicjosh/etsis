<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;
use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;

final readonly class StoredEvent implements \JsonSerializable
{
    public function __construct(
        public EntityId $id,
        public DomainEvent $event,
        public EventContext $context,
        public DateTimeImmutable $recordedAt,
        public string $hash,
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ["event" => "[protected historical event]"];
    }
    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
