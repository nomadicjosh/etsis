<?php

declare(strict_types=1);

namespace Domain\Admissions;

use DomainException;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;

final readonly class AdmissionApplication
{
    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public EntityId $programId,
        public EntityId $entryTermId,
        public ApplicationStatus $status = ApplicationStatus::Submitted,
        public int $version = 0,
    ) {
    }

    public function decide(bool $accept): self
    {
        if ($this->status !== ApplicationStatus::Submitted) {
            throw new DomainException("Only submitted applications can receive a decision.");
        }

        return new self(
            $this->id,
            $this->personId,
            $this->programId,
            $this->entryTermId,
            $accept ? ApplicationStatus::Accepted : ApplicationStatus::Rejected,
            $this->version + 1,
        );
    }

    public function correctDecision(bool $accept): self
    {
        $target = $accept ? ApplicationStatus::Accepted : ApplicationStatus::Rejected;
        if (
            !in_array($this->status, [ApplicationStatus::Accepted, ApplicationStatus::Rejected], true) ||
            $target === $this->status
        ) {
            throw new DomainException(
                "Only a different pre-matriculation admissions outcome can be corrected.",
            );
        }
        return new self(
            $this->id,
            $this->personId,
            $this->programId,
            $this->entryTermId,
            $target,
            $this->version + 1,
        );
    }

    public function matriculate(EntityId $studentId): Student
    {
        if ($this->status !== ApplicationStatus::Accepted) {
            throw new DomainException("An accepted application is required for matriculation.");
        }

        return new Student($studentId, $this->personId, $this->programId, $this->entryTermId);
    }
}
