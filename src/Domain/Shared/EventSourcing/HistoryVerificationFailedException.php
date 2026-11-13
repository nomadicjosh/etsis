<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Domain\Shared\ValueObject\EntityId;
use RuntimeException;

/** Safe operator reference to the support row; no aggregate identity, event data or upstream exception chain. */
final class HistoryVerificationFailedException extends RuntimeException
{
    public function __construct(public readonly ?EntityId $streamReference = null)
    {
        parent::__construct("Recorded stream could not be verified.");
    }
}
