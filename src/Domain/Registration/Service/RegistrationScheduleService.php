<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Registration\ValueObject\RegistrationScheduleDecision;
use Domain\Registration\ValueObject\MeetingOverlap;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal current assessment; callers establish owner/registrar authority and student locks. */
final readonly class RegistrationScheduleService
{
    public function __construct(
        private RegistrationScheduleRepository $records,
        private PublishedSectionMeetingPlanReader $plans,
    ) {
    }
    public function evaluate(
        EntityId $student,
        EntityId $section,
        ?RegistrationScheduleRead $read = null,
    ): RegistrationScheduleDecision {
        $read ??= new RegistrationScheduleRead();
        $policy = $this->records->policy();
        $target = $read->read($section, $this->plans);
        $refs = [$section->value => $this->reference($section->value, $target)];
        $enrollments = $conflicts = [];
        if ($target !== null && $target["definition"]["meetings"] !== []) {
            $rows = $this->records->enrollments($student, $section);
            if (count($rows) > 999) {
                $this->limit();
            }
            foreach ($rows as $row) {
                $id = new EntityId($row["section_id"]);
                $version = filter_var($row["version"], FILTER_VALIDATE_INT);
                if ($version === false || $version < 0) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
                $enrollments[] = [
                    "enrollment_id" => $row["id"],
                    "section_id" => $id->value,
                    "version" => $version,
                ];
                $plan =
                $row["publication_id"] !== null ||
                (int) ($row["history_version"] ?? 0) > 0
                ? $read->read($id, $this->plans)
                : null;
                $refs[$id->value] = $this->reference($id->value, $plan);
                $overlap =
                $plan === null
                ? null
                : MeetingOverlap::first(
                    $target["definition"]["meetings"],
                    $plan["definition"]["meetings"],
                );
                if ($overlap !== null) {
                    $conflicts[] =
                    [
                            "section_id" => $id->value,
                            "section_code" =>
                                $plan["definition"]["section_code"],
                        ] + $overlap;
                    if (count($conflicts) > 100) {
                        $this->limit();
                    }
                }
            }
        }
        ksort($refs);
        return new RegistrationScheduleDecision([
            "student_id" => $student->value,
            "target_section_id" => $section->value,
            "policy" => $policy,
            "plans" => array_values($refs),
            "enrollments" => $enrollments,
            "conflicts" => $conflicts,
        ]);
    }
    private function reference(string $id, ?array $plan): array
    {
        return $plan["reference"] ?? [
            "section_id" => $id,
            "publication_id" => null,
            "version" => null,
            "definition_hash" => null,
        ];
    }
    private function limit(): never
    {
        throw new ValidationException([
            [
                "field" => "schedule",
                "message" =>
                    "This timetable needs a bounded registrar review; no partial registration was saved.",
            ],
        ]);
    }
}
