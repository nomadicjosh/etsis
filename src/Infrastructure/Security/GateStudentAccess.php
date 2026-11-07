<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Codefy\Framework\Auth\Gate;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Exception\Data\TypeException;
use ReflectionException;

final readonly class GateStudentAccess implements StudentAccess
{
    public function __construct(private Gate $gate)
    {
    }

    /**
     * @throws ReflectionException
     */
    public function authenticatedUserId(): EntityId
    {
        return $this->actor();
    }

    /**
     * @throws ReflectionException
     * @throws TypeException
     */
    public function requirePermission(string $permission): EntityId
    {
        $actor = $this->actor();
        if (!$this->gate->can($permission)) {
            throw new AccessDeniedException('Access denied.');
        }

        return $actor;
    }

    /**
     * @throws ReflectionException
     * @throws TypeException
     */
    public function requireOwnerOrPermission(?EntityId $ownerUserId, string $permission): EntityId
    {
        $actor = $this->actor();
        if ($ownerUserId?->value !== $actor->value && !$this->gate->can($permission)) {
            throw new AccessDeniedException('Access denied.');
        }

        return $actor;
    }

    /**
     * @throws ReflectionException
     */
    private function actor(): EntityId
    {
        $user = $this->gate->current();
        if (!is_object($user) || !isset($user->user_id) || !is_string($user->user_id)) {
            throw new AccessDeniedException('Authentication required.');
        }

        return new EntityId($user->user_id);
    }
}
