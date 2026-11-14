<?php

declare(strict_types=1);

use Domain\Catalog\Repository\SpecializationPolicyRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSpecializationPolicies extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_specialization_policies', clear: true)) {
            $this->schema()->create('sis_specialization_policies', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('program_id', 26)->unique('ssp_program_unique');
                $t->integer('revision')->notNull();
                $t->integer('minimum')->notNull();
                $t->integer('maximum');
                $t->integer('default_maximum');
                $t->foreign('program_id', 'ssp_program_fk')->references('sis_programs', 'id');
            });
        }
        if (!$this->schema()->hasTable('sis_program_specialization_contexts', clear: true)) {
            $this->schema()->create('sis_program_specialization_contexts', static function (
                CreateTable $t,
            ): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('program_id', 26)->notNull();
                $t->integer('stream_version')->notNull();
                $t->text('snapshot_json')->size('medium')->notNull();
                $t->string('snapshot_hash', 64)->notNull();
                $t->index(['program_id', 'id'], 'spsc_program_page');
                $t->foreign('id', 'spsc_catalog_fk')->references('sis_program_catalogs', 'id');
                $t->foreign('program_id', 'spsc_program_fk')->references('sis_programs', 'id');
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('A policy migration needs its database adapter.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            if (
                $sql->rows(
                    'sis_specialization_policies',
                    ['id' => SpecializationPolicyRepository::INSTITUTION_ID],
                    1,
                ) === []
            ) {
                $sql->insert('sis_specialization_policies', [
                    'id' => SpecializationPolicyRepository::INSTITUTION_ID,
                    'program_id' => null,
                    'revision' => 0,
                    'minimum' => 0,
                    'maximum' => 1,
                    'default_maximum' => 1,
                ]);
            }
            $name = 'sis:catalog:policy';
            $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($old !== null && (int) $old['is_system'] !== 1) {
                throw new LogicException('The policy permission conflicts with a custom permission.');
            }
            $id = $old['id'] ?? EntityId::generate()->value;
            if ($old === null) {
                $sql->insert('sis_access_permissions', [
                    'id' => $id,
                    'name' => $name,
                    'description' => 'Configure institutional academic catalog policy boundaries.',
                    'is_system' => 1,
                ]);
            }
            if (
                $sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) ===
                []
            ) {
                $sql->insert('sis_access_implications', [
                    'id' => EntityId::generate()->value,
                    'ancestor_id' => $id,
                    'descendant_id' => $id,
                ]);
            }
        });
    }
    public function down(): void
    {
        throw new LogicException(
            'Preserve specialization policies and retained program contexts; restore a verified backup for rollback.',
        );
    }
}
