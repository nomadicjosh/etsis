<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

/** Current revision/serialization support only; publications remain in the one protected event journal. */
interface GradeComparisonPolicyState
{
    public function current(): int;
    public function advance(int $expected): void;
}
