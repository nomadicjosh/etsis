<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisApplicationFormScopes extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $pdo = $adapter->connection()->pdo;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            if ($pdo->inTransaction()) {
                throw new LogicException('Run the form-scope schema conversion outside an existing transaction.');
            }
            $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                $this->schema()->create('sis_application_forms_scoped', self::columns(...));
                $pdo->exec('INSERT INTO sis_application_forms_scoped '
                    . '(id, program_id, name, fields_json, status, version) '
                    . 'SELECT id, program_id, name, fields_json, status, version FROM sis_application_forms');
                $pdo->exec('DROP TABLE sis_application_forms');
                $pdo->exec('ALTER TABLE sis_application_forms_scoped RENAME TO sis_application_forms');
                if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                    throw new LogicException('Existing form references failed integrity verification.');
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            } finally {
                $pdo->exec('PRAGMA foreign_keys = ' . $foreignKeys);
            }
        } else {
            $this->schema()->alter('sis_application_forms', static function (AlterTable $table): void {
                $table->toString('program_id', 26)->set(name: 'nullable', value: true);
                $table->string('academic_level_id', 26);
                $table->foreign('academic_level_id', 'saf_level_fk')->references('sis_catalog_classifications', 'id');
            });
        }
        $this->schema()->alter('sis_application_forms', static function (AlterTable $table): void {
            $table->index(['academic_level_id', 'id'], 'saf_level_idx');
        });
        $this->schema()->create('sis_level_forms', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('academic_level_id', 26)->notNull()->unique('slf_level_uq');
            $table->string('form_id', 26)->notNull();
            $table->foreign('academic_level_id', 'slf_level_fk')->references('sis_catalog_classifications', 'id');
            $table->foreign('form_id', 'slf_form_fk')->references('sis_application_forms', 'id');
        });
    }

    private static function columns(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('program_id', 26);
        $table->string('academic_level_id', 26);
        $table->string('name', 191)->notNull();
        $table->text('fields_json')->notNull();
        $table->string('status', 16)->notNull();
        $table->integer('version')->notNull();
        $table->foreign('program_id', 'saf_program_fk')->references('sis_programs', 'id');
        $table->foreign('academic_level_id', 'saf_level_fk')->references('sis_catalog_classifications', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve form publications, target bindings and submitted answers.');
    }
}
