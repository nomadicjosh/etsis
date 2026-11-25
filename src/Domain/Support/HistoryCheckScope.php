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
        public ?EntityId $person = null,
        public ?EntityId $owner = null,
        public ?EntityId $student = null,
        public ?EntityId $pursuit = null,
        public ?EntityId $employee = null,
        public ?EntityId $section = null,
        public ?EntityId $course = null,
        public ?EntityId $term = null,
    ) {
        if ($kind === HistoryCheckSubject::GraduatePolicy) {
            if (
                $subject->value !== \Domain\Registration\GraduateRegistrationPolicy::ID
                || $person !== null || $owner !== null || $student !== null || $pursuit !== null || $employee !== null
                || $section !== null || $course !== null || $term !== null
            ) {
                throw new \InvalidArgumentException('Use the non-personal institutional graduate policy scope.');
            }
            return;
        }
        $catalog = in_array($kind, [HistoryCheckSubject::Course, HistoryCheckSubject::Section], true);
        if (
            $catalog ? ($person !== null || $owner !== null || $student !== null || $pursuit !== null
            || $employee !== null || $course === null
            || ($kind === HistoryCheckSubject::Course
                && ($course->value !== $subject->value || $section !== null || $term !== null))
            || ($kind === HistoryCheckSubject::Section && ($section?->value !== $subject->value || $term === null)))
            : ($person === null || $course !== null || $term !== null)
        ) {
            throw new \InvalidArgumentException('Use canonical personal or non-personal catalog routing.');
        }
    }

    public function criteria(): array
    {
        return [
            "subject_type" => $this->kind->value,
            "subject_id" => $this->subject->value,
            "person_id" => $this->person?->value,
            "owner_user_id" => $this->owner?->value,
            "student_id" => $this->student?->value,
        ];
    }
}
