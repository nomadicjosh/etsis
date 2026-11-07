<?php

declare(strict_types=1);

namespace Infrastructure\Security\Rbac;

use Codefy\Framework\Auth\Rbac\Entity\Permission;
use Codefy\Framework\Auth\Rbac\Entity\Role;
use Codefy\Framework\Auth\Rbac\Resource\StorageResource;
use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\AccessService;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Persistence\SqlRecords;

/** On-demand framework RBAC compatibility; never hydrates a graph during load or access checks. */
final readonly class SqlStorageResource implements StorageResource
{
    public function __construct(private SqlRecords $sql, private AccessService $service)
    {
    }

    public function load(): void
    {
        // The database is authoritative. No snapshot or graph cache is loaded.
    }

    public function save(): void
    {
        // Mutations commit through AccessService, including authorization and audit.
    }

    public function clear(): void
    {
        throw new DomainException('System access definitions cannot be cleared.');
    }

    public function addRole(string $name, string $description = ''): Role
    {
        return new SqlRole($this->service->create(DefinitionKind::Role, $name, $description), $this);
    }

    public function addPermission(string $name, string $description = ''): Permission
    {
        return new SqlPermission($this->service->create(DefinitionKind::Permission, $name, $description), $this);
    }

    public function getRole(string $name): ?Role
    {
        $definition = $this->named(DefinitionKind::Role, $name);

        return $definition === null ? null : new SqlRole($definition, $this);
    }

    public function getPermission(string $name): ?Permission
    {
        $definition = $this->named(DefinitionKind::Permission, $name);

        return $definition === null ? null : new SqlPermission($definition, $this);
    }

    public function deleteRole(string $name): void
    {
        $this->service->delete(DefinitionKind::Role, $this->required(DefinitionKind::Role, $name)->id);
    }

    public function deletePermission(string $name): void
    {
        $this->service->delete(DefinitionKind::Permission, $this->required(DefinitionKind::Permission, $name)->id);
    }

    public function assignPermission(string $role, string $permission, bool $grant): void
    {
        $this->service->assign(
            GrantKind::RolePermission,
            $this->required(DefinitionKind::Role, $role)->id,
            $this->required(DefinitionKind::Permission, $permission)->id,
            $grant
        );
    }

    public function roleAllows(string $role, string $permission): bool
    {
        $q = $this->sql->quote(...);

        return $this->sql->fetch(
            'SELECT r.' . $q('id') . ' FROM ' . $q('sis_access_roles') . ' r WHERE r.'
            . $q('name') . ' = ? AND ((r.' . $q('name') . " = 'super' AND r." . $q('is_system') . ' = 1)'
            . ' OR EXISTS (SELECT 1 FROM ' . $q('sis_access_role_permissions') . ' g JOIN '
            . $q('sis_access_implications') . ' c ON c.' . $q('ancestor_id') . ' = g.' . $q('permission_id')
            . ' JOIN ' . $q('sis_access_permissions') . ' p ON p.' . $q('id') . ' = c.' . $q('descendant_id')
            . ' WHERE g.' . $q('role_id') . ' = r.' . $q('id') . ' AND p.' . $q('name') . ' = ?))',
            [$role, $permission],
            1
        ) !== [];
    }

    /**
     * Explicit enumeration only; authorization uses roleAllows().
     * @return array<string, Permission>
     */
    public function rolePermissions(string $role, bool $withChildren): array
    {
        $q = $this->sql->quote(...);
        $join = $withChildren ? ' JOIN ' . $q('sis_access_implications') . ' c ON c.' . $q('ancestor_id')
        . ' = g.' . $q('permission_id') : '';
        $target = $withChildren ? 'c.' . $q('descendant_id') : 'g.' . $q('permission_id');
        $rows = $this->sql->fetch('SELECT DISTINCT p.' . $q('id') . ', p.' . $q('name') . ', p.'
            . $q('description') . ', p.' . $q('is_system') . ' FROM ' . $q('sis_access_roles')
            . ' r JOIN ' . $q('sis_access_role_permissions') . ' g ON g.' . $q('role_id') . ' = r.' . $q('id')
            . $join . ' JOIN ' . $q('sis_access_permissions') . ' p ON p.' . $q('id') . ' = ' . $target
            . ' WHERE r.' . $q('name') . ' = ? ORDER BY p.' . $q('name'), [$role]);

        return $this->permissions($rows);
    }

    /** @return array<string, Permission> */
    public function permissionChildren(string $name): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT p.* FROM ' . $q('sis_access_permissions') . ' p JOIN '
            . $q('sis_access_implications') . ' c ON c.' . $q('descendant_id') . ' = p.' . $q('id')
            . ' JOIN ' . $q('sis_access_permissions') . ' a ON a.' . $q('id') . ' = c.' . $q('ancestor_id')
            . ' WHERE a.' . $q('name') . ' = ? AND a.' . $q('id') . ' <> p.' . $q('id')
            . ' ORDER BY p.' . $q('name'), [$name]);

        return $this->permissions($rows);
    }

    private function named(DefinitionKind $kind, string $name): ?AccessDefinition
    {
        $rows = $this->sql->rows(
            $kind === DefinitionKind::Role ? 'sis_access_roles' : 'sis_access_permissions',
            ['name' => $name],
            1
        );

        return $rows === [] ? null : $this->definition($rows[0]);
    }

    private function required(DefinitionKind $kind, string $name): AccessDefinition
    {
        return $this->named($kind, $name) ?? throw new RecordNotFoundException('Access definition not found.');
    }

    /** @param array<string, mixed> $row */
    private function definition(array $row): AccessDefinition
    {
        return new AccessDefinition(
            new EntityId($row['id']),
            $row['name'],
            $row['description'],
            (bool) $row['is_system']
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, Permission>
     */
    private function permissions(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['name']] = new SqlPermission($this->definition($row), $this);
        }

        return $result;
    }
}
