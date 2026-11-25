<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\ValueObject\SectionProgramPolicy;
use Domain\Shared\ValueObject\EntityId;

interface SectionProgramPolicyRepository
{
    public function current(EntityId $section): SectionProgramPolicy;
    public function programs(EntityId $section): array;
    public function choices(string $search, ?EntityId $after = null): array;
    /** Caller owns the section lock. Lock old and proposed program references in identity order. */
    public function lockReferences(EntityId $section, array $additional = []): void;
    public function program(EntityId $program): array;
    public function assign(EntityId $section, SectionProgramPolicy $policy): void;
}
