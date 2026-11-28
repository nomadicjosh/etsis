<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Domain\Shared\ValueObject\EntityId;

/** Internal selected evidence, never an ordinary reporting or customer API. Callers authorize scope. */
interface HistoricalEventEvidence
{
    public function recordedEvent(EntityId $event): StoredEvent;

    /** @return list<StoredEvent>|null Null only when this operation has no receipt. */
    public function operationEvents(EntityId $operation): ?array;
}
