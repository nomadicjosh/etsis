<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\GraduateHistoryCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted read-only observation. No current eligibility calculation, permission grant or repair. */
interface GraduateHistoryVerifier
{
    public function policy(int $eventLimit = 500): GraduateHistoryCheck;
    public function approvals(EntityId $pursuit, int $eventLimit = 500): GraduateHistoryCheck;
}
