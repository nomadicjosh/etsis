<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWorkspaceTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_application_forms', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('program_id', 26)->notNull();
            $table->string('name', 191)->notNull();
            $table->text('fields_json')->notNull();
            $table->string('status', 16)->notNull();
            $table->integer('version')->notNull();
            $table->foreign('program_id', 'saf_program_fk')->references('sis_programs', 'id');
        });
        $this->schema()->create('sis_program_forms', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('program_id', 26)->notNull()->unique('spf_program');
            $table->string('form_id', 26)->notNull();
            $table->foreign('program_id', 'spf_program_fk')->references('sis_programs', 'id');
            $table->foreign('form_id', 'spf_form_fk')->references('sis_application_forms', 'id');
        });
        $this->schema()->create('sis_form_submissions', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('form_id', 26)->notNull();
            $table->string('application_id', 26)->notNull()->unique('sfs_application');
            $table->string('owner_user_id', 36)->notNull();
            $table->text('encrypted_answers')->notNull();
            $table->string('fingerprint', 64)->notNull();
            $table->foreign('form_id', 'sfs_form_fk')->references('sis_application_forms', 'id');
            $table->foreign('application_id', 'sfs_application_fk')->references('sis_applications', 'id');
            $table->foreign('owner_user_id', 'sfs_owner_fk')->references('users', 'user_id');
        });
        $this->schema()->create('sis_rule_definitions', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->string('name', 191)->notNull();
            $table->text('rules_json')->notNull();
            $table->integer('version')->notNull();
            $table->foreign('owner_user_id', 'srd_owner_fk')->references('users', 'user_id');
        });
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Workspace migration requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql, $db): void {
            $group = $sql->one('sis_access_permissions', ['name' => 'sis']);
            foreach (['sis:forms:manage', 'sis:forms:publish', 'sis:rules:manage', 'sis:rules:run'] as $name) {
                $existing = $sql->rows('sis_access_permissions', ['name' => $name], 1);
                if ($existing !== []) {
                    continue;
                }
                $id = EntityId::generate()->value;
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => str_replace(':', ' ', $name), 'is_system' => 1]);
                foreach ([$id, $group['id']] as $ancestor) {
                    $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                        'ancestor_id' => $ancestor, 'descendant_id' => $id]);
                }
            }
            $people = new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $sql,
                new \Infrastructure\Persistence\Repository\PdoAdmissionsRepository($db),
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            );
            $q = $sql->quote(...);
            $after = null;
            do {
                $users = $sql->fetch('SELECT ' . $q('user_id') . ', ' . $q('first_name') . ', ' . $q('last_name')
                    . ' FROM ' . $q('users') . ($after === null ? '' : ' WHERE ' . $q('user_id') . ' > ?')
                    . ' ORDER BY ' . $q('user_id'), $after === null ? [] : [$after], 500);
                foreach ($users as $user) {
                    $people->ensure(
                        new EntityId($user['user_id']),
                        trim($user['first_name'] ?? '') ?: 'Account',
                        trim($user['last_name'] ?? '') ?: 'Holder'
                    );
                    $after = $user['user_id'];
                }
            } while (count($users) === 500);
        });
    }

    public function down(): void
    {
        // Permission definitions remain protected and keep administrator assignments across schema rollback.
        $tables = ['sis_rule_definitions', 'sis_form_submissions', 'sis_program_forms', 'sis_application_forms'];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
