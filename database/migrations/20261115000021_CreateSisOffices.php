<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisOffices extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Office setup needs a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        if (
            !$this->schema()->hasTable('sis_offices', true)
            && $sql->rows('sis_history_streams', ['stream_type' => 'person_restrictions'], 1) !== []
        ) {
            throw new LogicException('Convert recorded hold authority explicitly before this alpha upgrade.');
        }
        if (!$this->schema()->hasTable('sis_offices', true)) {
            $this->schema()->create('sis_offices', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('code', 22)->notNull()->unique('so_code');
                $t->string('name', 120)->notNull();
                $t->integer('active')->notNull();
                $t->integer('revision')->notNull();
            });
        }
        if (!$this->schema()->hasTable('sis_office_delegations', true)) {
            $this->schema()->create('sis_office_delegations', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('office_id', 26)->notNull();
                $t->string('user_id', 36)->notNull();
                $t->string('starts_on', 10)->notNull();
                $t->string('ends_on', 10);
                $t->integer('active')->notNull();
                $t->integer('revision')->notNull();
                $t->unique(['office_id', 'user_id'], 'sod_pair');
                $t->index(['user_id', 'active', 'office_id'], 'sod_user_scope');
                $t->foreign('office_id', 'sod_office_fk')->references('sis_offices', 'id');
                $t->foreign('user_id', 'sod_user_fk')->references('users', 'user_id');
            });
        }
        if (!$this->schema()->hasTable('sis_restriction_offices', true)) {
            $this->schema()->create('sis_restriction_offices', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('office_id', 26)->notNull();
                $t->index(['office_id', 'id'], 'sro_office_page');
                $t->foreign('id', 'sro_hold_fk')->references('sis_restrictions', 'id');
                $t->foreign('office_id', 'sro_office_fk')->references('sis_offices', 'id');
            });
        }
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = 'sis:restrictions:manage';
            $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($old !== null && (int) $old['is_system'] !== 1) {
                throw new LogicException('Hold management conflicts with a custom permission.');
            }
            $id = $old['id'] ?? EntityId::generate()->value;
            if ($old === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Manage holds in explicitly delegated offices.', 'is_system' => 1]);
            }
            $sis = $sql->one('sis_access_permissions', ['name' => 'sis']);
            foreach ([$id, $sis['id']] as $ancestor) {
                if (
                    $sql->rows('sis_access_implications', ['ancestor_id' => $ancestor, 'descendant_id' => $id], 1)
                        === []
                ) {
                    $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                        'ancestor_id' => $ancestor, 'descendant_id' => $id]);
                }
            }
            $registration = $sql->rows('sis_access_permissions', ['name' => 'sis:registration:manage'], 1)[0] ?? null;
            if (
                $registration !== null && (int) $registration['is_system'] === 1
                && $registration['description'] === 'Manage registration and student restrictions.'
            ) {
                $sql->update(
                    'sis_access_permissions',
                    ['description' => 'Manage registration and enrollment changes.'],
                    ['id' => $registration['id']]
                );
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Office routing and delegations require explicit disposition before removal.');
    }
}
