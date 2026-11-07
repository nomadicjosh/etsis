<?php

declare(strict_types=1);

namespace Infrastructure\Security\Rbac;

use Codefy\Framework\Auth\Rbac\Entity\Permission;
use Codefy\Framework\Auth\Rbac\Entity\Role;
use Domain\Access\AccessDefinition;
use DomainException;

final readonly class SqlRole implements Role
{
    public string $name;
    public string $description;

    public function __construct(AccessDefinition $definition, private SqlStorageResource $storage)
    {
        $this->name = $definition->name;
        $this->description = $definition->description;
    }

    public function addChild(Role $role): void
    {
        throw new DomainException('Role inheritance is not supported; assign explicit role permissions.');
    }

    public function removeChild(string $roleName): void
    {
        throw new DomainException('Role inheritance is not supported; assign explicit role permissions.');
    }

    public function getChildren(): array
    {
        return [];
    }

    public function addPermission(Permission $permission): void
    {
        $this->storage->assignPermission($this->name, $permission->name, true);
    }

    public function removePermission(string $permissionName): void
    {
        $this->storage->assignPermission($this->name, $permissionName, false);
    }

    public function getPermissions(bool $withChildren = false): array
    {
        return $this->storage->rolePermissions($this->name, $withChildren);
    }

    public function checkAccess(string $permissionName, ?array $params = null): bool
    {
        return $this->storage->roleAllows($this->name, $permissionName);
    }
}
