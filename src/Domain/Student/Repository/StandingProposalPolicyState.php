<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

/** Current revision/serialization support only; publications remain in the one protected event journal. */
interface StandingProposalPolicyState
{
    public function current(): int;
    public function advance(int $expected): void;
}
