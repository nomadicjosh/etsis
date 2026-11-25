<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Infrastructure\Persistence\PostgresReferencedColumnCompiler;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionChangeRequests extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_admission_change_requests', clear: true)) {
            $define = static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('application_id', 26)->notNull();
                $t->string('person_id', 26)->notNull();
                $t->string('owner_user_id', 26)->notNull();
                $t->string('area', 20)->notNull();
                $t->string('status', 12)->notNull();
                $t->integer('version')->notNull();
                $t->text('sealed_data')->size('medium')->notNull();
                $t->index(['application_id', 'status', 'id'], 'sacr_app_page');
                $t->index(['application_id', 'area', 'status'], 'sacr_active_area');
                $t->foreign('application_id', 'sacr_app_fk')->references('sis_applications', 'id');
                $t->foreign('person_id', 'sacr_person_fk')->references('sis_persons', 'id');
            };
            $adapter = $this->getAdapter();
            if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
                throw new LogicException('A connection-aware migration adapter is required.');
            }
            $connection = $adapter->connection();
            if (!str_starts_with((string) $connection->getDsn(), 'pgsql:')) {
                $this->schema()->create('sis_admission_change_requests', $define);
                return;
            }
            // Converted PostgreSQL IDs may carry a nondeterministic collation.
            // A referencing string must retain the parent column's exact collation.
            $collations = [];
            $query = $connection->pdo->prepare(
                'SELECT collation_schema, collation_name FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
            );
            foreach (['application_id' => 'sis_applications', 'person_id' => 'sis_persons'] as $column => $parent) {
                $query->execute([$parent, 'id']);
                $reference = $query->fetch(PDO::FETCH_ASSOC);
                if ($reference === false) {
                    throw new LogicException('A referenced identity column is missing.');
                }
                if ($reference['collation_name'] !== null) {
                    $collations[$column] = $connection->quoteIdentifier($reference['collation_schema'])
                    . '.' . $connection->quoteIdentifier($reference['collation_name']);
                }
            }
            $compiler = new PostgresReferencedColumnCompiler($connection, $collations);
            $table = new CreateTable('sis_admission_change_requests');
            $define($table);
            $owned = !$connection->pdo->inTransaction();
            if ($owned) {
                $connection->pdo->beginTransaction();
            }
            try {
                foreach ($compiler->create($table) as $statement) {
                    $connection->command(sql: $statement['sql'], params: $statement['params']);
                }
                if ($owned) {
                    $connection->pdo->commit();
                }
            } catch (Throwable $error) {
                if ($owned && $connection->pdo->inTransaction()) {
                    $connection->pdo->rollBack();
                }
                throw $error;
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve private application follow-ups until approved retention/disposition.');
    }
}
