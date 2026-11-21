<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\ContactHoursPolicyReader;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionContactTimeRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\ContactHours;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class SectionContactHoursService
{
    public function __construct(
        private SectionContactTimeRepository $records,
        private ContactHoursPolicyReader $policies,
        private RegistrationRepository $sections,
        private SectionVersionRepository $versions,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }
    /** Internal publication input. The section publication service owns authorization and locks. */
    public function snapshot(EntityId $section): ?array
    {
        $draft = $this->records->current($section);
        if ($draft["weekly_minutes"] === null) {
            return null;
        }
        $policy = $this->policies->current();
        return new ContactHours([
            "weekly_instruction_minutes" => $draft["weekly_minutes"],
            "unit_minutes" => $policy["unit_minutes"],
            "rounding" => $policy["rounding"],
            "weekly_contact_milli" => ContactHours::calculate(
                $draft["weekly_minutes"],
                $policy["unit_minutes"],
                $policy["rounding"],
            ),
            "policy_publication_id" => $policy["publication_id"],
            "policy_version" => $policy["version"],
        ])->data;
    }
    public function view(EntityId $section): array
    {
        $actor = $this->authorize($section);
        $current = $this->records->current($section);
        $selected = SectionOfferingSelection::verify($section, $this->versions, $this->history);
        $result = $current + [
            "section_code" => $this->sections->section($section)->code,
            "calculation" => $this->snapshot($section),
            "policy" => $this->policies->current(),
            "selected_recorded" =>
                $selected !== null &&
                array_key_exists("contact_hours", $selected->definition->data),
            "selected" => $selected?->definition->data["contact_hours"] ?? null,
        ];
        $this->authorize($section, $actor);
        return $result;
    }
    public function save(EntityId $section, ?int $minutes, int $version): void
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if ($minutes !== null && ($minutes < 0 || $minutes > 10080)) {
            throw new ValidationException([
                [
                    "field" => "weekly_minutes",
                    "message" =>
                        "Enter weekly instructional minutes from zero through 10080, or leave unknown.",
                ],
            ]);
        }
        $this->transactions->run(function () use ($section, $minutes, $version, $actor): void {
            $this->roles->lock();
            $this->sections->lockSection($section);
            $current = $this->records->current($section);
            if ($current["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Section contact time changed. Reload it.",
                );
            }
            if ($current["weekly_minutes"] !== $minutes) {
                $this->records->save($section, $minutes, $version);
                $this->audit->record($actor, "section.contact_time_saved", $section);
            }
            if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        });
    }
    private function authorize(EntityId $section, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $s = $this->sections->section($section);
        try {
            $scoped = $this->access->requirePermission("sis:hr:manage");
        } catch (AccessDeniedException) {
            $scoped = $this->access->requireOwnerOrPermission(
                $s->facultyUserId,
                "sis:catalog:manage",
            );
        }
        if (
            $scoped->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
}
