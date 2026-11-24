<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Registration\Repository\RegistrationUpdatesRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Called only for a new accepted event in the caller's authorized atomic transaction. */
final readonly class WaitlistOutcomeNotifications
{
    public function __construct(
        private AdmissionsRepository $people,
        private RegistrationUpdatesRepository $updates,
    ) {
    }

    public function record(
        EntityId $section,
        EntityId $operation,
        int $version,
        array $entry,
        DateTimeImmutable $at,
    ): void {
        if (!in_array($entry["status"], ["registered", "removed"], true)) {
            return;
        }
        $student = $this->people->student(new EntityId($entry["student_id"]));
        if ($student->personId->value !== $entry["person_id"]) {
            throw new CorruptHistoryException();
        }
        $person = $this->people->person($student->personId);
        if ($person->ownerUserId === null) {
            return; // An older unlinked person has no private recipient; never invent one.
        }
        $this->updates->record([
            "id" => EntityId::generate()->value,
            "owner_user_id" => $person->ownerUserId->value,
            "person_id" => $person->id->value,
            "student_id" => $student->id->value,
            "section_id" => $section->value,
            "entry_id" => $entry["id"],
            "operation_id" => $operation->value,
            "revision" => $version,
            "status" => $entry["status"],
            "recorded_at" => $at
                ->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z"),
            "read_at" => null,
        ]);
    }
}
