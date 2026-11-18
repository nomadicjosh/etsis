<?php

declare(strict_types=1);

namespace Tests\Support;

use SisFixture;
use EventStoreFixture;
use Domain\Registration\Event\SectionMeetingPlanWasPublished;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\SectionMeetingPlanId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

final class RegistrationScheduleFixture
{
    public SisFixture $sis;
    public function __construct(?SisFixture $sis = null)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $sis ?? new SisFixture();
    }
    /** A fictional confirmed plan uses the real protected journal.
     * Publisher checks have separate integration coverage. */
    public function publish(Section $section, array $times): EntityId
    {
        $f = $this->sis;
        $history = EventStoreFixture::history($f->connection->queryBuilder());
        $stream = new StreamReference(
            new SectionMeetingPlanId($section->id->value),
            "section_meeting_plan",
        );
        $head = $history->latest($stream);
        $publication = EntityId::generate();
        $definition = new SectionMeetingPlanDefinition([
            "section_id" => $section->id->value,
            "section_code" => $section->code,
            "term_code" => "Fictional term",
            "timezone" => "America/Los_Angeles",
            "locations" => [],
            "meetings" => array_map(
                static fn(array $time): array => [
                    "booking_id" => EntityId::generate()->value,
                    "occurrence_id" => EntityId::generate()->value,
                    "offering_publication_id" => EntityId::generate()->value,
                    "room_id" => null,
                    "starts_at" => $time[0],
                    "ends_at" => $time[1],
                ],
                $times,
            ),
        ]);
        $event = SectionMeetingPlanWasPublished::occur(
            $stream->aggregateId,
            [
                "version_id" => $publication->value,
                "previous_version_id" => $head?->event->payload()["version_id"],
                "definition" => $definition->data,
            ],
            ["__aggregate_type" => "section_meeting_plan"],
        )->withPlayhead(($head?->event->playhead() ?? 0) + 1);
        $f->transactions->run(static function () use (
            $history,
            $stream,
            $publication,
            $head,
            $event,
            $f,
            $section,
            $definition,
        ): void {
            $history->append(
                $stream,
                $publication,
                $head?->event->playhead() ?? 0,
                new EventContext(
                    $f->access->actor,
                    $f->clock->now(),
                    $publication,
                    reason: "Fictional confirmed meeting plan.",
                ),
                [$event],
            );
            new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                $f->sql,
                $f->bookingSections(),
            )->retain(
                $section->id,
                $publication,
                $event->playhead(),
                $definition->fingerprint(),
            );
        });
        return $publication;
    }
    public function policy(): \Domain\Registration\Service\RegistrationSchedulePolicyService
    {
        $f = $this->sis;
        return new \Domain\Registration\Service\RegistrationSchedulePolicyService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository(
                $f->sql,
            ),
            $f->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository(
                $f->sql,
            ),
            $f->transactions,
            $f->audit,
        );
    }
    public function batch(): \Domain\Registration\Service\RegistrationBatchService
    {
        $f = $this->sis;
        return $f->registrationBatches();
    }
    public function service(
        \Domain\Audit\Repository\AuditTrail $audit,
    ): \Domain\Registration\Service\RegistrationService {
        $f = $this->sis;
        return new \Domain\Registration\Service\RegistrationService(
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->termFees(),
            $audit,
            $f->clock,
            $f->drops,
            $f->academicCredits(),
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->primaryProgramLedger(),
            $f->workPursuits(),
            $f->prerequisites(),
            $f->schedules(),
        );
    }
    public function digest(): string
    {
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_audit_log",
        ];
        $data = [];
        foreach ($tables as $table) {
            if ($table === "sis_audit_log") {
                continue;
            }
            $data[$table] = $this->sis->sql->rows($table);
        }
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }
}
