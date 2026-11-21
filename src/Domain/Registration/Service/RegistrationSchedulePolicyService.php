<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Audited institutional configuration. Accepted registrations retain the exact assessed policy. */
final readonly class RegistrationSchedulePolicyService
{
    public function __construct(
        private RegistrationScheduleRepository $records,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\Registration\Repository\CoRequisiteRepository $corequisites,
    ) {
    }
    public function current(): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $policy = $this->records->policy();
        $value = $policy + [
            "corequisites" => $this->corequisites->policy(),
            "faculty_mode" => $this->records->facultyPolicy()["mode"],
        ];
        $this->authorize($actor, false);
        return $value;
    }
    public function save(
        string $mode,
        int $revision,
        ?array $corequisites = null,
        ?string $facultyMode = null,
    ): array {
        $actor = $this->access->requirePermission("sis:registration:policy");
        return $this->transactions->run(function () use (
            $mode,
            $revision,
            $actor,
            $corequisites,
            $facultyMode,
        ): array {
            $this->permissions->lock();
            $this->records->lockPolicy();
            $this->authorize($actor, true);
            $expectedFacultyMode = $facultyMode ?? $this->records->facultyPolicy()["mode"];
            $this->records->savePolicy($mode, $revision, $facultyMode);
            if ($corequisites !== null) {
                $this->corequisites->savePolicy(
                    $corequisites["prior_completion"],
                    $corequisites["drop_mode"],
                    $corequisites["revision"],
                );
            }
            $this->audit->record(
                $actor,
                "registration.schedule_policy_changed",
                new EntityId(RegistrationScheduleRepository::POLICY_ID),
            );
            $this->authorize($actor, true);
            $saved = $this->records->policy();
            if (
                $saved !== ["mode" => $mode, "revision" => $revision + 1] ||
                $this->records->facultyPolicy()["mode"] !== $expectedFacultyMode
            ) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    "The timetable policy changed while saving. Reload it.",
                );
            }
            return $saved + [
                "corequisites" => $this->corequisites->policy(),
                "faculty_mode" => $this->records->facultyPolicy()["mode"],
            ];
        });
    }
    private function authorize(EntityId $actor, bool $write): void
    {
        if (
            $this->access->requirePermission("sis:registration:manage")->value !== $actor->value ||
            ($write && $this->access->requirePermission("sis:registration:policy")->value !== $actor->value)
        ) {
            throw new AccessDeniedException("The registration policy actor changed.");
        }
    }
}
