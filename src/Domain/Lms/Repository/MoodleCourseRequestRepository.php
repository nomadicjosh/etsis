<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleCourseRequestRepository
{
    /** @return array<string,mixed>|null */
    public function forSection(EntityId $binding, EntityId $section): ?array;
    /** @param array<string,scalar|null> $row */
    public function add(array $row): void;
    /** @return array<string,mixed>|null */
    public function claim(int $now): ?array;
    public function finish(EntityId $id, int $version, string $state, string $issue, int $lease): void;
}
