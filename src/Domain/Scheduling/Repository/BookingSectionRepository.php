<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Published academic context, referenced by operational room reservations. */
interface BookingSectionRepository
{
    /** Verify the selected offering; lock its section during booking confirmation.
     * @return array<string,string>
     */
    public function selected(EntityId $section, bool $lock): array;
    /** Retained projection labels for a prior booking; never select today's replacement.
     * @return array<string,string>
     */
    public function describe(EntityId $publication): array;
}
