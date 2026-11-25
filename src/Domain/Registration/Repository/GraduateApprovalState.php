<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Technical stream checkpoint only; authoritative approvals remain in the shared protected journal. */
interface GraduateApprovalState
{
    public function current(EntityId $pursuit): int;
    public function advance(EntityId $pursuit, int $expected): void;
}
