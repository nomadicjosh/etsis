<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface SectionContactTimeRepository
{
    /** @return array{weekly_minutes:?int,version:int} */
    public function current(EntityId $section): array;
    public function save(EntityId $section, ?int $minutes, int $version): void;
}
