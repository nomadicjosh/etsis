<?php

declare(strict_types=1);

namespace Domain\Support;

use Domain\Shared\ValueObject\EntityId;
use Domain\Support\Enum\HistoryCheckSubject;

/** Immutable current routing, without personal values or financial facts. */
final readonly class HistoryCheckScope
{
    public function __construct(
        public HistoryCheckSubject $kind,
        public EntityId $subject,
        public EntityId $person,
        public ?EntityId $owner,
        public ?EntityId $student,
    ) {
    }

    public function criteria(): array
    {
        return [
            "subject_type" => $this->kind->value,
            "subject_id" => $this->subject->value,
            "person_id" => $this->person->value,
            "owner_user_id" => $this->owner?->value,
            "student_id" => $this->student?->value,
        ];
    }
}
