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
        $this->authorize($actor, false);
        return $policy + ["corequisites" => $this->corequisites->policy()];
    }
    public function save(string $mode, int $revision, ?array $corequisites = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:policy");
        return $this->transactions->run(function () use (
            $mode,
            $revision,
            $actor,
            $corequisites,
        ): array {
            $this->permissions->lock();
            $this->records->lockPolicy();
            $this->authorize($actor, true);
            $this->records->savePolicy($mode, $revision);
            if ($corequisites !== null) {
                $this->corequisites->savePolicy(
                    $corequisites['prior_completion'],
                    $corequisites['drop_mode'],
                    $corequisites['revision']
                );
            }
            $this->audit->record(
                $actor,
                "registration.schedule_policy_changed",
                new EntityId(RegistrationScheduleRepository::POLICY_ID),
            );
            $this->authorize($actor, true);
            return $this->records->policy() + ["corequisites" => $this->corequisites->policy()];
        });
    }
    private function authorize(EntityId $actor, bool $write): void
    {
        if (
            $this->access->requirePermission("sis:registration:manage")
                ->value !== $actor->value ||
            ($write &&
                $this->access->requirePermission("sis:registration:policy")
                    ->value !== $actor->value)
        ) {
            throw new AccessDeniedException(
                "The registration policy actor changed.",
            );
        }
    }
}
