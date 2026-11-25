<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\ValueObject\EntityId;

/** Trusted selected-request observation; no seat, academic, financial or notification effects. */
interface WaitlistProjectionVerifier
{
    public function verify(EntityId $entry, int $eventLimit = 500): WaitlistProjectionCheck;
}
