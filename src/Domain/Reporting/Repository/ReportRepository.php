<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Reporting\StudentReport;
use Domain\Shared\ValueObject\EntityId;

interface ReportRepository
{
    public function add(StudentReport $report): void;
    public function isRelational(EntityId $id): bool;
    public function get(EntityId $id): StudentReport;
    public function share(EntityId $report, EntityId $user): void;
    public function lock(EntityId $report): void;
    public function unshare(EntityId $report, EntityId $user): void;
    /** @return list<array<string, mixed>> */
    public function shares(EntityId $report, ?EntityId $after, int $limit): array;
    public function isSharedWith(EntityId $report, EntityId $user): bool;
    /** @return list<array<string, mixed>> */
    public function run(StudentReport $report, ?EntityId $after, int $limit): array;
    /** @return array<string, mixed> */
    public function describe(EntityId $report): array;
}
