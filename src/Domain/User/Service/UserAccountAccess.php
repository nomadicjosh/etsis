<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Closure;
use DomainException;
use Domain\Access\Repository\AccessRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Keep the existing user editor from becoming a way to take over a super account. */
final readonly class UserAccountAccess
{
    public function __construct(
        private AccessRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
    ) {
    }

    /** @template T
     * @param Closure():T $operation
     * @return T
     */
    public function update(EntityId $target, Closure $operation): mixed
    {
        return $this->transactions->run(function () use ($target, $operation): mixed {
            $this->repository->lock();
            $this->authorizeUpdate($target);
            return $operation();
        });
    }

    public function authorizeUpdate(EntityId $target): void
    {
        $actor = $this->access->authenticatedUserId();
        $permission = $actor->value === $target->value ? 'admin:profile' : 'admin:edit:user';
        $this->access->requirePermission($permission);
        if ($this->repository->isSuper($target->value) && !$this->repository->isSuper($actor->value)) {
            throw new AccessDeniedException('Only a super administrator can edit a super account.');
        }
    }

    public function delete(EntityId $target, Closure $operation): void
    {
        $this->transactions->run(function () use ($target, $operation): void {
            $this->repository->lock();
            $this->access->requirePermission('admin:delete:user');
            if ($this->repository->isSuper($target->value)) {
                throw new DomainException('Revoke the super role before deleting this account.');
            }
            $operation();
        });
    }
}
