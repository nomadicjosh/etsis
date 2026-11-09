<?php

declare(strict_types=1);

namespace Domain\Registration;

use DateTimeImmutable;
use DomainException;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Shared\ValueObject\EntityId;

final readonly class Enrollment
{
    public function __construct(
        public EntityId $id,
        public EntityId $studentId,
        public EntityId $sectionId,
        public EntityId $termId,
        public RegistrationStatus $status,
        public DateTimeImmutable $registeredAt,
        public DateTimeImmutable $statusAt,
        public int $version = 0,
    ) {
    }

    public function drop(Term $term, DateTimeImmutable $on): self
    {
        if (!$this->status->isEnrolled() || $term->id->value !== $this->termId->value || $on < $this->registeredAt) {
            throw new DomainException('Only a current enrollment may be dropped on or after registration.');
        }

        return new self(
            $this->id,
            $this->studentId,
            $this->sectionId,
            $this->termId,
            $term->dropStatusOn($on),
            $this->registeredAt,
            $on,
            $this->version + 1
        );
    }

    public function withdrawOnDate(Term $term, DateTimeImmutable $on): self
    {
        if (
            !$this->status->isEnrolled() || $term->id->value !== $this->termId->value
            || $on->format('Y-m-d') < $this->registeredAt->setTimezone($on->getTimezone())->format('Y-m-d')
        ) {
            throw new DomainException('A current enrollment and a valid effective date are required.');
        }
        return new self(
            $this->id,
            $this->studentId,
            $this->sectionId,
            $this->termId,
            $term->dropStatusOn($on),
            $this->registeredAt,
            $on,
            $this->version + 1
        );
    }
}
