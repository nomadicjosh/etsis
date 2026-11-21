<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use Domain\Registration\Repository\FacultyScheduleRepository;
use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Registration\ValueObject\FacultyConflictAssessment;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal publication assessment. Callers establish authority; ordinary timetables remain read-only. */
final readonly class FacultyScheduleService
{
    public function __construct(
        private FacultyScheduleRepository $records,
        private RegistrationScheduleRepository $policies,
        private HistoricalEventStore $history,
        private PublishedSectionMeetingPlanReader $plans,
    ) {
    }

    /** Serialize meeting publications and policy changes, without locking ordinary registration reads. */
    public function lock(): void
    {
        $this->policies->lockPolicy();
    }

    public function assess(SectionMeetingPlanDefinition $plan): FacultyConflictAssessment
    {
        $budget = new HistoryReadBudget();
        $offerings = new MeetingPlanOfferingReader($this->history);
        $own = $offerings->read($plan, $budget);
        $faculty = [];
        $unassigned = 0;
        foreach ($plan->data["meetings"] as $meeting) {
            $person = $own[$meeting["offering_publication_id"]]["faculty_user_id"];
            if ($person === null) {
                ++$unassigned;
            } else {
                $faculty[$person] = true;
            }
        }
        if (count($faculty) > 20) {
            self::limit();
        }
        ksort($faculty, SORT_STRING);
        $policy = $this->policies->facultyPolicy();
        $data = [
            "mode" => $policy["mode"],
            "revision" => $policy["revision"],
            "faculty_ids" => array_keys($faculty),
            "unassigned_meetings" => $unassigned,
            "sources" => [],
            "pairs" => 0,
            "conflicts" => [],
        ];
        if ($faculty === []) {
            return new FacultyConflictAssessment($data);
        }
        $first = min(array_column($plan->data["meetings"], "starts_at"));
        $last = max(array_column($plan->data["meetings"], "ends_at"));
        // Conservative date bounds include every institutional timezone at UTC date boundaries.
        $candidates = $this->records->candidates(
            array_keys($faculty),
            new DateTimeImmutable($first)->modify("-1 day")->format("Y-m-d"),
            new DateTimeImmutable($last)->modify("+1 day")->format("Y-m-d"),
            new EntityId($plan->data["section_id"]),
        );
        if (count($candidates) > 50) {
            self::limit();
        }
        sort($candidates, SORT_STRING);
        $inspected = 0;
        foreach ($candidates as $id) {
            $head = $this->plans->read(new EntityId($id));
            if ($head === null) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    "A compared meeting plan changed. Review again.",
                );
            }
            $other = new SectionMeetingPlanDefinition($head->event->payload()["definition"]);
            $inspected += count($other->data["meetings"]);
            if ($inspected > 2000) {
                self::limit();
            }
            $definitions = $offerings->read($other, $budget);
            $data["sources"][] = [
                "section_id" => $id,
                "publication_id" => $head->event->payload()["version_id"],
                "version" => $head->event->playhead(),
                "definition_hash" => $other->fingerprint(),
            ];
            foreach ($plan->data["meetings"] as $meeting) {
                $person = $own[$meeting["offering_publication_id"]]["faculty_user_id"];
                if ($person === null) {
                    continue;
                }
                foreach ($other->data["meetings"] as $comparison) {
                    if ($comparison["starts_at"] >= $meeting["ends_at"]) {
                        break;
                    }
                    if (
                        $definitions[$comparison["offering_publication_id"]]["faculty_user_id"] !== $person ||
                        $comparison["ends_at"] <= $meeting["starts_at"]
                    ) {
                        continue;
                    }
                    ++$data["pairs"];
                    if (count($data["conflicts"]) < 20) {
                        $data["conflicts"][] = [
                            "section_id" => $id,
                            "section_code" => $other->data["section_code"],
                            "term_code" => $other->data["term_code"],
                            "own_occurrence_id" => $meeting["occurrence_id"],
                            "other_occurrence_id" => $comparison["occurrence_id"],
                            "starts_at" => max($meeting["starts_at"], $comparison["starts_at"]),
                            "ends_at" => min($meeting["ends_at"], $comparison["ends_at"]),
                        ];
                    }
                }
            }
        }
        return new FacultyConflictAssessment($data);
    }

    private static function limit(): never
    {
        throw new ValidationException([
            [
                "field" => "meetings",
                "message" =>
                    implode("", [
                    "This faculty review exceeds 20 faculty accounts, 50 compared sections ",
                    "or 2,000 compared meetings. ",
                    "No partial assessment was used. Review a smaller schedule window.",
                ]),
            ],
        ]);
    }
}
