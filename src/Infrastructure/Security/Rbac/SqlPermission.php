<?php

declare(strict_types=1);

namespace Infrastructure\Security\Rbac;

use Codefy\Framework\Auth\Rbac\Entity\Permission;
use Domain\Access\AccessDefinition;
use DomainException;

final readonly class SqlPermission implements Permission
{
    public string $name;
    public string $description;

    public function __construct(AccessDefinition $definition, private SqlStorageResource $storage)
    {
        $this->name = $definition->name;
        $this->description = $definition->description;
    }

    public function addChild(Permission $permission): void
    {
        throw new DomainException('Permission implications are protected system definitions.');
    }

    public function removeChild(string $permissionName): void
    {
        throw new DomainException('Permission implications are protected system definitions.');
    }

    public function getChildren(): array
    {
        return $this->storage->permissionChildren($this->name);
    }

    public function setRuleClass(string $ruleClass): void
    {
        throw new DomainException('Executable permission rules cannot be configured through storage.');
    }

    public function getRuleClass(): ?string
    {
        return null;
    }

    public function checkAccess(?array $params = null): bool
    {
        // A definition is not a user grant. User authorization remains with the database Gate.
        return $this->storage->getPermission($this->name) !== null;
    }
}
