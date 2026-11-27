<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\MeetingPlanCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted operator read, with no booking, publication, roster, notification or repair side effects. */
interface MeetingPlanVerifier
{
    public function verify(EntityId $section, int $eventLimit = 500): MeetingPlanCheck;
}
