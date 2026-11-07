<?php

declare(strict_types=1);

namespace Domain\Access\Service;

use DomainException;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;

/** Deployment-only use case. Never register this operation on an HTTP route. */
final readonly class BootstrapAccess
{
    public function __construct(
        private AccessRepository $repository,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function assignFirstAdministrator(EntityId $user): void
    {
        $this->transactions->run(function () use ($user): void {
            $this->repository->lock();
            $this->repository->requireUser($user);
            if ($this->repository->anySuper()) {
                throw new DomainException('A super administrator already exists. Use access administration.');
            }
            $role = $this->repository->roleNamed('super');
            $this->repository->assign(GrantKind::UserRole, $user, $role->id, true);
            $this->audit->record($user, 'access.bootstrap', $user);
        });
    }
}
