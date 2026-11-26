<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeZone;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\Repository\AcademicUpdatesRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

/** Newly accepted grade commands only; replay and accepted retries never recreate personal notices. */
final readonly class AcademicNotifications
{
    public function __construct(
        private AcademicUpdatesRepository $updates,
        private AcademicCreditRepository $records,
        private AdmissionsRepository $people,
        private Clock $clock,
    ) {
    }

    public function record(array $credit, EntityId $section, EntityId $operation, bool $amendment): array
    {
        $student = new EntityId($credit["student_id"]);
        $person = $this->people->person($this->people->student($student)->personId);
        $id = new EntityId($credit["id"]);
        $reference = null;
        if ($person->ownerUserId !== null) {
            $this->updates->replace([
                "id" => DerivedIdentity::from($operation, "academic.grade-notification")->value,
                "owner_user_id" => $person->ownerUserId->value,
                "person_id" => $person->id->value,
                "student_id" => $student->value,
                "credit_id" => $id->value,
                "section_id" => $section->value,
                "enrollment_id" => $credit["source_id"],
                "revision" => $credit["version"],
                "status" => $amendment ? "corrected" : "posted",
                "recorded_at" => $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z"),
                "read_at" => null,
            ]);
            $reference = $this->updates->current($id);
        }
        return [
            "credit" => $credit,
            "person_id" => $person->id->value,
            "owner" => $person->ownerUserId?->value,
            "reference" => $reference,
        ];
    }

    public function verify(array $context): void
    {
        $credit = $context["credit"];
        $person = $this->people->person($this->people->student(new EntityId($credit["student_id"]))->personId);
        if (
            $person->id->value !== $context["person_id"] ||
            $person->ownerUserId?->value !== $context["owner"] ||
            $this->records->projection(new EntityId($credit["id"])) != $credit ||
            ($context["owner"] !== null &&
                ($context["reference"] === null ||
                    $this->updates->current(new EntityId($credit["id"])) !== $context["reference"]))
        ) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans_html(
                    "The academic notification or its owner changed before completion.",
                ),
            );
        }
    }
}
