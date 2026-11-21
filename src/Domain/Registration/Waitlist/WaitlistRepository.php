<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\ValueObject\EntityId;

interface WaitlistRepository
{
    /** Caller holds the section lock before consequential reads/writes. */
    public function current(EntityId $section): ?array;
    public function active(EntityId $section): array;
    public function save(EntityId $section, int $expected, array $projection, ?array $entry): void;
    public function mine(EntityId $student, ?EntityId $after = null): array;
}
