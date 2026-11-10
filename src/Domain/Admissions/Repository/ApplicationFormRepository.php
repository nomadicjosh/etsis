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
    public function disable(EntityId $program): void;
    public function lockPerson(EntityId $person): void;
    public function lockProgram(EntityId $program): void;
    /** @return list<array<string, mixed>> */
    public function listing(): array;
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
        string $fingerprint
    ): void;
}
