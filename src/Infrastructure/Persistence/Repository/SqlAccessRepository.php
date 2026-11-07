<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Repository\AccessRepository;
use Domain\Access\Service\AccessService;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

/** No permission graph or user-wide permission set is hydrated on the authorization path. */
final readonly class SqlAccessRepository implements AccessRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function allows(string $userId, string $permission): bool
    {
        $users = $this->sql->quote('users');
        $permissions = $this->sql->quote('sis_access_permissions');
        $user = $this->sql->quote('user_id');
        $name = $this->sql->quote('name');
        $id = $this->sql->quote('id');
        $match = $this->superMatch("u.$user");

        return $this->sql->fetch("SELECT u.$user FROM $users u WHERE u.$user = ? AND ($match OR EXISTS "
            . "(SELECT p.$id FROM $permissions p WHERE p.$name = ? AND "
            . $this->permissionMatch("u.$user") . '))', [$userId, $permission], 1) !== [];
    }

    public function anyoneCanManage(): bool
    {
        if ($this->anySuper()) {
            return true;
        }
        $q = $this->sql->quote(...);
        $users = $q('users');
        $permissions = $q('sis_access_permissions');
        $closure = $q('sis_access_implications');
        $direct = $q('sis_access_user_permissions');
        $assignments = $q('sis_access_user_roles');
        $roleGrants = $q('sis_access_role_permissions');
        $user = $q('user_id');
        $id = $q('id');
        $name = $q('name');
        $ancestor = $q('ancestor_id');
        $descendant = $q('descendant_id');
        $permission = $q('permission_id');
        $role = $q('role_id');
        $directMatch = "EXISTS (SELECT 1 FROM $closure c JOIN $direct d ON d.$permission = c.$ancestor "
        . "JOIN $users u ON u.$user = d.$user WHERE c.$descendant = p.$id)";
        $roleMatch = "EXISTS (SELECT 1 FROM $closure c JOIN $roleGrants r ON r.$permission = c.$ancestor "
        . "JOIN $assignments a ON a.$role = r.$role JOIN $users u ON u.$user = a.$user "
        . "WHERE c.$descendant = p.$id)";

        return $this->sql->fetch("SELECT p.$id FROM $permissions p WHERE p.$name = ? AND "
            . "($directMatch OR $roleMatch)", [AccessService::MANAGE], 1) !== [];
    }

    public function isSuper(string $userId): bool
    {
        $users = $this->sql->quote('users');
        $user = $this->sql->quote('user_id');

        return $this->sql->fetch("SELECT u.$user FROM $users u WHERE u.$user = ? AND "
            . $this->superMatch("u.$user"), [$userId], 1) !== [];
    }

    public function anySuper(): bool
    {
        $q = $this->sql->quote(...);
        $assignments = $q('sis_access_user_roles');
        $roles = $q('sis_access_roles');
        $users = $q('users');
        $role = $q('role_id');
        $user = $q('user_id');
        $id = $q('id');
        $name = $q('name');
        $system = $q('is_system');

        return $this->sql->fetch("SELECT sr.$id FROM $roles sr JOIN $assignments sa ON sa.$role = sr.$id "
            . "JOIN $users u ON u.$user = sa.$user WHERE sr.$name = ? AND sr.$system = 1", ['super'], 1) !== [];
    }

    private function superMatch(string $userReference): string
    {
        $q = $this->sql->quote(...);
        $assignments = $q('sis_access_user_roles');
        $roles = $q('sis_access_roles');
        $role = $q('role_id');
        $user = $q('user_id');
        $id = $q('id');
        $name = $q('name');
        $system = $q('is_system');

        return "EXISTS (SELECT 1 FROM $assignments sa JOIN $roles sr ON sr.$id = sa.$role "
        . "WHERE sa.$user = $userReference AND sr.$name = 'super' AND sr.$system = 1)";
    }

    /** The only variable SQL fragment is a trusted caller-owned user reference or a placeholder. */
    private function permissionMatch(string $userReference): string
    {
        $q = $this->sql->quote(...);
        $up = $q('sis_access_user_permissions');
        $ur = $q('sis_access_user_roles');
        $rp = $q('sis_access_role_permissions');
        $closure = $q('sis_access_implications');
        $ancestor = $q('ancestor_id');
        $descendant = $q('descendant_id');
        $permission = $q('permission_id');
        $role = $q('role_id');
        $user = $q('user_id');
        $id = $q('id');

        return "(EXISTS (SELECT 1 FROM $up d JOIN $closure c ON c.$ancestor = d.$permission "
        . "WHERE d.$user = $userReference AND c.$descendant = p.$id) "
        . "OR EXISTS (SELECT 1 FROM $ur a JOIN $rp r ON r.$role = a.$role "
        . "JOIN $closure c ON c.$ancestor = r.$permission "
        . "WHERE a.$user = $userReference AND c.$descendant = p.$id))";
    }

    public function lock(): void
    {
        $this->sql->lock('sis_access_state', '00000000000000000000000000', 'id');
    }

    public function requireUser(EntityId $user): void
    {
        $id = $this->sql->quote('user_id');
        $users = $this->sql->quote('users');
        if ($this->sql->fetch("SELECT $id FROM $users WHERE $id = ?", [$user->value], 1) === []) {
            throw new RecordNotFoundException('User not found.');
        }
    }

    public function roleNamed(string $name): AccessDefinition
    {
        return $this->hydrate($this->sql->one('sis_access_roles', ['name' => $name]));
    }

    public function definition(DefinitionKind $kind, EntityId $id): AccessDefinition
    {
        return $this->hydrate($this->sql->one($this->table($kind), ['id' => $id->value]));
    }

    public function add(DefinitionKind $kind, AccessDefinition $definition): void
    {
        if ($this->sql->rows($this->table($kind), ['name' => $definition->name], 1) !== []) {
            throw new DomainException('An access definition with that name already exists.');
        }
        $this->sql->insert($this->table($kind), [
            'id' => $definition->id->value,
            'name' => $definition->name,
            'description' => $definition->description,
            'is_system' => 0,
        ]);
        if ($kind === DefinitionKind::Permission) {
            $this->sql->insert('sis_access_implications', [
                'id' => EntityId::generate()->value,
                'ancestor_id' => $definition->id->value,
                'descendant_id' => $definition->id->value,
            ]);
        }
    }

    public function updateDescription(DefinitionKind $kind, EntityId $id, string $description): void
    {
        $this->sql->update(
            $this->table($kind),
            ['description' => $description],
            ['id' => $id->value, 'is_system' => 0],
        );
    }

    public function remove(DefinitionKind $kind, EntityId $id): void
    {
        $definition = $this->definition($kind, $id);
        if ($definition->isSystem) {
            throw new DomainException('System roles and permissions cannot be edited or deleted.');
        }
        if ($kind === DefinitionKind::Role) {
            $this->deleteRows('sis_access_user_roles', 'role_id', $id->value);
            $this->deleteRows('sis_access_role_permissions', 'role_id', $id->value);
        } else {
            $this->deleteRows('sis_access_user_permissions', 'permission_id', $id->value);
            $this->deleteRows('sis_access_role_permissions', 'permission_id', $id->value);
            $this->deleteRows('sis_access_implications', 'ancestor_id', $id->value);
            $this->deleteRows('sis_access_implications', 'descendant_id', $id->value);
        }
        $this->deleteRows($this->table($kind), 'id', $id->value);
    }

    public function definitions(DefinitionKind $kind, ?EntityId $after, int $limit): array
    {
        $id = $this->sql->quote('id');
        $where = $after === null ? '' : " WHERE $id > ?";

        return array_map($this->hydrate(...), $this->sql->fetch(
            'SELECT * FROM ' . $this->sql->quote($this->table($kind)) . $where . " ORDER BY $id",
            $after === null ? [] : [$after->value],
            $limit,
        ));
    }

    public function directory(
        DefinitionKind $kind,
        string $search,
        ?EntityId $after,
        int $limit,
        ?GrantKind $grant,
        ?EntityId $subject,
        bool $assignedOnly
    ): array {
        $q = $this->sql->quote(...);
        $parameters = [];
        $where = [];
        $select = 'p.' . $q('id') . ', p.' . $q('name') . ', p.' . $q('description') . ', p.' . $q('is_system');
        $assigned = null;
        if ($grant !== null && $subject !== null) {
            [$table, $owner, $target] = $this->grantColumns($grant);
            $assigned = 'EXISTS (SELECT 1 FROM ' . $q($table) . ' ag WHERE ag.' . $q($owner)
            . ' = ? AND ag.' . $q($target) . ' = p.' . $q('id') . ')';
            $select .= ', CASE WHEN ' . $assigned . ' THEN 1 ELSE 0 END AS ' . $q('assigned');
            $parameters[] = $subject->value;
            if ($grant === GrantKind::UserPermission) {
                $effective = 'EXISTS (SELECT 1 FROM ' . $q('users') . ' u WHERE u.' . $q('user_id')
                . ' = ? AND (' . $this->superMatch('u.' . $q('user_id')) . ' OR '
                . $this->permissionMatch('u.' . $q('user_id')) . '))';
                $select .= ', CASE WHEN ' . $effective . ' THEN 1 ELSE 0 END AS ' . $q('effective');
                $parameters[] = $subject->value;
            } elseif ($grant === GrantKind::RolePermission) {
                $effective = 'EXISTS (SELECT 1 FROM ' . $q('sis_access_role_permissions') . ' rg JOIN '
                . $q('sis_access_implications') . ' im ON im.' . $q('ancestor_id') . ' = rg.'
                . $q('permission_id') . ' WHERE rg.' . $q('role_id') . ' = ? AND im.'
                . $q('descendant_id') . ' = p.' . $q('id') . ')';
                $select .= ', CASE WHEN ' . $effective . ' THEN 1 ELSE 0 END AS ' . $q('effective');
                $parameters[] = $subject->value;
            }
        }
        if ($search !== '') {
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $where[] = '(LOWER(p.' . $q('name') . ") LIKE ? ESCAPE '!' OR LOWER(p."
            . $q('description') . ") LIKE ? ESCAPE '!')";
            $parameters = [...$parameters, $term, $term];
        }
        if ($after !== null) {
            $where[] = 'p.' . $q('id') . ' > ?';
            $parameters[] = $after->value;
        }
        if ($assignedOnly && $assigned !== null) {
            $where[] = $assigned;
            $parameters[] = $subject->value;
        }

        return $this->sql->fetch('SELECT ' . $select . ' FROM ' . $q($this->table($kind)) . ' p'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY p.' . $q('id'), $parameters, $limit);
    }

    public function assignments(GrantKind $kind, EntityId $subject, ?EntityId $after, int $limit): array
    {
        [$table, $owner, $target] = $this->grantColumns($kind);
        $q = $this->sql->quote(...);
        $where = $after === null ? '' : ' AND ' . $q($target) . ' > ?';
        $rows = $this->sql->fetch(
            'SELECT ' . $q($target) . ' FROM ' . $q($table)
            . ' WHERE ' . $q($owner) . ' = ?' . $where . ' ORDER BY ' . $q($target),
            $after === null ? [$subject->value] : [$subject->value, $after->value],
            $limit
        );

        return array_map(static fn (array $row): string => (string) $row[$target], $rows);
    }

    public function assign(GrantKind $kind, EntityId $subject, EntityId $target, bool $grant): void
    {
        [$table, $owner, $column] = $this->grantColumns($kind);
        $criteria = [$owner => $subject->value, $column => $target->value];
        if ($grant && $this->sql->rows($table, $criteria, 1) === []) {
            $this->sql->insert($table, ['id' => EntityId::generate()->value, ...$criteria]);
        } elseif (!$grant) {
            $this->sql->execute(
                'DELETE FROM ' . $this->sql->quote($table) . ' WHERE '
                . $this->sql->quote($owner) . ' = ? AND ' . $this->sql->quote($column) . ' = ?',
                [$subject->value, $target->value]
            );
        }
    }

    public function assignDefaultRole(string $userId): void
    {
        $role = $this->sql->one('sis_access_roles', ['name' => 'user']);
        $this->sql->insert('sis_access_user_roles', [
            'id' => EntityId::generate()->value, 'user_id' => $userId, 'role_id' => (string) $role['id'],
        ]);
    }

    public function removeUserAssignments(string $userId): void
    {
        $this->deleteRows('sis_access_user_roles', 'user_id', $userId);
        $this->deleteRows('sis_access_user_permissions', 'user_id', $userId);
    }

    private function table(DefinitionKind $kind): string
    {
        return $kind === DefinitionKind::Role ? 'sis_access_roles' : 'sis_access_permissions';
    }

    /** @return array{string, string, string} */
    private function grantColumns(GrantKind $kind): array
    {
        return match ($kind) {
            GrantKind::RolePermission => ['sis_access_role_permissions', 'role_id', 'permission_id'],
            GrantKind::UserRole => ['sis_access_user_roles', 'user_id', 'role_id'],
            GrantKind::UserPermission => ['sis_access_user_permissions', 'user_id', 'permission_id'],
        };
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AccessDefinition
    {
        return new AccessDefinition(
            new EntityId((string) $row['id']),
            (string) $row['name'],
            (string) ($row['description'] ?? ''),
            (bool) $row['is_system']
        );
    }

    private function deleteRows(string $table, string $column, string $value): void
    {
        $this->sql->execute('DELETE FROM ' . $this->sql->quote($table) . ' WHERE '
            . $this->sql->quote($column) . ' = ?', [$value]);
    }
}
