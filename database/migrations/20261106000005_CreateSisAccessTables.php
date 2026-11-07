<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAccessTables extends Migration
{
    public function up(): void
    {
        foreach (['sis_access_roles', 'sis_access_permissions'] as $name) {
            if (!$this->schema()->hasTable($name, true)) {
                $this->schema()->create($name, static function (CreateTable $table) use ($name): void {
                    $table->string('id', 26)->primary()->notNull();
                    $table->string('name', 80)->notNull()->unique($name . '_name');
                    $table->string('description', 255);
                    $table->integer('is_system')->notNull();
                });
            }
        }
        $links = [
            'sis_access_role_permissions' => [
                'role_id', 'sis_access_roles', 'permission_id', 'sis_access_permissions', 'sarp',
            ],
            'sis_access_user_roles' => ['user_id', 'users', 'role_id', 'sis_access_roles', 'saur'],
            'sis_access_user_permissions' => ['user_id', 'users', 'permission_id', 'sis_access_permissions', 'saup'],
            'sis_access_implications' => [
                'ancestor_id', 'sis_access_permissions', 'descendant_id', 'sis_access_permissions', 'saim',
            ],
        ];
        foreach ($links as $name => [$left, $leftTable, $right, $rightTable, $prefix]) {
            if ($this->schema()->hasTable($name, true)) {
                continue;
            }
            $this->schema()->create($name, static function (CreateTable $table) use (
                $left,
                $leftTable,
                $right,
                $rightTable,
                $prefix
            ): void {
                $table->string('id', 26)->primary()->notNull();
                $table->string($left, $leftTable === 'users' ? 36 : 26)->notNull();
                $table->string($right, 26)->notNull();
                $table->unique([$left, $right], $prefix . '_pair');
                $table->unique([$right, $left], $prefix . '_reverse');
                $table->foreign($left, $prefix . '_left_fk')
                    ->references($leftTable, $leftTable === 'users' ? 'user_id' : 'id');
                $table->foreign($right, $prefix . '_right_fk')->references($rightTable, 'id');
            });
        }
        if (!$this->schema()->hasTable('sis_access_state', true)) {
            $this->schema()->create('sis_access_state', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
            });
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Access migration requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, function () use ($sql): void {
            if ($sql->rows('sis_access_state', ['id' => '00000000000000000000000000']) !== []) {
                return;
            }
            // One-time import only: neither application boot nor subsequent migrations restore revoked access.
            $config = require dirname(__DIR__, 2) . '/config/rbac.php';
            $permissions = [];
            $this->permissions($sql, $config['permissions'], [], $permissions);
            $roles = [];
            foreach ($config['roles'] as $name => $data) {
                $id = EntityId::generate()->value;
                $roles[$name] = $id;
                $sql->insert('sis_access_roles', [
                    'id' => $id, 'name' => $name, 'description' => $data['description'] ?? '', 'is_system' => 1,
                ]);
                foreach ($data['permissions'] ?? [] as $permission) {
                    $sql->insert('sis_access_role_permissions', [
                        'id' => EntityId::generate()->value,
                        'role_id' => $id, 'permission_id' => $permissions[$permission],
                    ]);
                }
            }
            $userId = $sql->quote('user_id');
            $roleName = $sql->quote('role');
            $users = $sql->quote('users');
            $after = null;
            do {
                $batch = $sql->fetch(
                    "SELECT $userId, $roleName FROM $users"
                    . ($after === null ? '' : " WHERE $userId > ?") . " ORDER BY $userId",
                    $after === null ? [] : [$after],
                    500
                );
                foreach ($batch as $user) {
                    $role = $roles[$user['role'] ?? 'user'] ?? $roles['user'];
                    $sql->insert('sis_access_user_roles', [
                        'id' => EntityId::generate()->value, 'user_id' => $user['user_id'], 'role_id' => $role,
                    ]);
                    $after = $user['user_id'];
                }
            } while (count($batch) === 500);
            $sql->insert('sis_access_state', ['id' => '00000000000000000000000000']);
        });
    }

    /**
     * @param array<string, mixed> $definitions
     * @param list<string> $ancestors
     * @param array<string, string> $ids
     */
    private function permissions(SqlRecords $sql, array $definitions, array $ancestors, array &$ids): void
    {
        foreach ($definitions as $name => $data) {
            $id = EntityId::generate()->value;
            $ids[$name] = $id;
            $sql->insert('sis_access_permissions', [
                'id' => $id, 'name' => $name, 'description' => $data['description'] ?? '', 'is_system' => 1,
            ]);
            foreach ([$id, ...$ancestors] as $ancestor) {
                $sql->insert('sis_access_implications', [
                    'id' => EntityId::generate()->value, 'ancestor_id' => $ancestor, 'descendant_id' => $id,
                ]);
            }
            $this->permissions($sql, $data['permissions'] ?? [], [...$ancestors, $id], $ids);
        }
    }

    public function down(): void
    {
        foreach (
            [
            'sis_access_state', 'sis_access_implications', 'sis_access_user_permissions',
            'sis_access_user_roles', 'sis_access_role_permissions', 'sis_access_permissions', 'sis_access_roles',
            ] as $table
        ) {
            $this->schema()->drop($table);
        }
    }
}
