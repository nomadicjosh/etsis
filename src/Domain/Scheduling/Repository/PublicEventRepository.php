<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;

interface PublicEventRepository
{
    /** Internal, minimized source; never includes the private booking purpose or owner.
     * @return array<string,mixed>
     */
    public function source(EntityId $booking): array;
    /** @param array<string,mixed> $source */
    public function lock(EntityId $booking, array $source): void;
    /** @return array<string,mixed>|null */
    public function current(EntityId $booking): ?array;
    /** @param array<string,mixed> $record
     * @param list<array<string,string>> $times
     */
    public function publish(array $record, array $times, int $expectedVersion): void;
    public function withdraw(EntityId $booking, int $expectedVersion): void;
    /** Safe public columns only; filters run before the complete-result bound.
     * @return list<array<string,mixed>>
     */
    public function calendar(TimeSlot $window, string $search, int $limit): array;

    /** All matching public times for one booking, probing one beyond its 366-meeting bound.
     * @return list<array<string,mixed>>
     */
    public function publishedTimes(EntityId $booking): array;
}
