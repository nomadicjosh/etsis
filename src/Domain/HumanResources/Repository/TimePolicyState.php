<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

/** Current revision/serialization support only; publications remain in the one protected event journal. */
interface TimePolicyState
{
    public function current(): int;
    public function advance(int $expected): void;
}
