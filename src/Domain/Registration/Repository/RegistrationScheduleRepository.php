<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RegistrationScheduleRepository
{
    public const string POLICY_ID = "01J00000000000000000000RG0";
    public function lockPolicy(): void;
    /** @return array{mode:string,revision:int} */
    public function policy(): array;
    public function savePolicy(string $mode, int $revision, ?string $facultyMode = null): void;
    /** @return array{mode:string,revision:int} */
    public function facultyPolicy(): array;
    /** At most 1,000 candidates; includes other terms and excludes closed enrollments and the target section.
     * @return list<array<string,mixed>> */
    public function enrollments(EntityId $student, EntityId $target): array;
}
