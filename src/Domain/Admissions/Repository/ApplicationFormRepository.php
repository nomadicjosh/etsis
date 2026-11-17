<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\ApplicationForm;
use Domain\Shared\ValueObject\EntityId;

interface ApplicationFormRepository
{
    public function get(EntityId $id): ApplicationForm;
    public function active(EntityId $program): ApplicationForm;
    public function save(ApplicationForm $form, ?int $expectedVersion): void;
    public function publish(ApplicationForm $form): void;
    public function disable(EntityId $program, ?EntityId $expectedForm): void;
    public function lockProgram(EntityId $program): void;
    public function lockLevel(EntityId $level): void;
    public function disableLevel(EntityId $level, ?EntityId $expectedForm): void;
    /** @return list<array<string,mixed>> */
    public function levels(): array;
    /** @return array{items:list<array<string,mixed>>,next_after:?string} */
    public function listing(string $search = '', string $state = 'all', ?EntityId $after = null): array;
    /** @return array<string, mixed>|null */
    public function submission(EntityId $id): ?array;
    /** @return array<string, mixed>|null */
    public function submissionForApplication(EntityId $application): ?array;
    /** @return array<string, mixed> */
    public function applicationLabels(EntityId $application): array;
    public function storeSubmission(
        EntityId $id,
        EntityId $form,
        EntityId $application,
        EntityId $owner,
        string $encrypted,
        string $fingerprint,
    ): void;
}
