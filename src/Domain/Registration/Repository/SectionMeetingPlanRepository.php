<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Reconstructible current pointer and bounded working-schedule inputs. No authoritative history here. */
interface SectionMeetingPlanRepository
{
    /** @return array<string, mixed> */
    public function capture(EntityId $section, string $timezone): array;

    /** Rooms, bookings, buildings, then section; no plain reads before acquiring these locks.
     * @param array<string, mixed> $candidate
     */
    public function lock(EntityId $section, array $candidate): void;

    /** @return array<string, mixed>|null */
    public function current(EntityId $section): ?array;

    public function retain(EntityId $section, EntityId $publication, int $version, string $fingerprint): void;
}
