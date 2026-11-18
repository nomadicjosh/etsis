<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Audited institutional configuration; issued offers retain their original policy. */
final readonly class AdmissionPolicyService
{
    public function __construct(
        private AdmissionPolicyRepository $policy,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }
    public function current(): array
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        $data = ['offers' => $this->policy->offers(), 'withdrawals' => $this->policy->withdrawals()];
        $this->authorize($actor, false);
        return $data;
    }
    public function save(string $area, bool|string $value, int $revision): array
    {
        $actor = $this->access->requirePermission('sis:admissions:policy');
        return $this->transactions->run(function () use ($area, $value, $revision, $actor): array {
            $this->permissions->lock();
            $this->policy->lock();
            $this->authorize($actor, true);
            if ($area === 'offers' && is_bool($value)) {
                $this->policy->saveOffers($value, $revision);
            } elseif ($area === 'withdrawals' && is_string($value)) {
                $this->policy->saveWithdrawals($value, $revision);
            } else {
                throw new \InvalidArgumentException('Choose an admissions policy and valid value.');
            }
            $this->audit->record(
                $actor,
                'admissions.' . $area . '_policy_changed',
                new EntityId(AdmissionPolicyRepository::ID)
            );
            $this->authorize($actor, true);
            return $area === 'offers' ? $this->policy->offers() : $this->policy->withdrawals();
        });
    }
    private function authorize(EntityId $actor, bool $write): void
    {
        if (
            $this->access->requirePermission('sis:admissions:manage')->value !== $actor->value
            || ($write && $this->access->requirePermission('sis:admissions:policy')->value !== $actor->value)
        ) {
            throw new AccessDeniedException('The admissions policy actor changed.');
        }
    }
}
