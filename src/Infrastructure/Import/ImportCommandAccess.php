<?php

declare(strict_types=1);

namespace Infrastructure\Import;

use Domain\Access\Repository\AccessRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\LoginAccountStateReader;

use function Codefy\Framework\Helpers\trans;

/** Immutable server command principal, independent of any browser session. */
final readonly class ImportCommandAccess implements StudentAccess
{
    public function __construct(
        private EntityId $actor,
        private AccessRepository $roles,
        private LoginAccountStateReader $accounts,
    ) {
    }

    public function authenticatedUserId(): EntityId
    {
        if ($this->accounts->current($this->actor) === null) {
            throw new AccessDeniedException(
                trans("The import account can no longer sign in."),
            );
        }
        return $this->actor;
    }

    public function requirePermission(string $permission): EntityId
    {
        $actor = $this->authenticatedUserId();
        if (!$this->roles->allows($actor->value, $permission)) {
            throw new AccessDeniedException(
                trans(
                    "The import account no longer has the required permission.",
                ),
            );
        }
        return $actor;
    }

    public function requireOwnerOrPermission(
        ?EntityId $ownerUserId,
        string $permission,
    ): EntityId {
        return $this->authenticatedUserId()->value === $ownerUserId?->value
        ? $this->actor
        : $this->requirePermission($permission);
    }
}
