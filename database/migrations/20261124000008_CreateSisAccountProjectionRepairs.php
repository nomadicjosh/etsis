<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Infrastructure\Persistence\PostgresReferencedColumnCompiler;

final class CreateSisAccountProjectionRepairs extends Migration
{
    public function up(): void
    {
        if ($this->schema()->hasTable('sis_account_projection_repairs', clear: true)) {
            return;
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $connection = $adapter->connection();
        if (!str_starts_with((string) $connection->getDsn(), 'pgsql:')) {
            $this->schema()->create('sis_account_projection_repairs', self::define(...));
            return;
        }
        // Reuse the migration compiler that preserves converted PostgreSQL reference collations.
        $parents = ['account_id' => ['sis_student_accounts', 'id']];
        $collations = [];
        $query = $connection->pdo->prepare(
            'SELECT collation_schema, collation_name FROM information_schema.columns '
            . 'WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
        );
        foreach ($parents as $column => $parent) {
            $query->execute($parent);
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
        $table = new CreateTable('sis_account_projection_repairs');
        self::define($table);
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

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('account_id', 26)->notNull();
        $t->string('completed_at', 27)->notNull();
        $t->text('sealed_result')->size('medium')->notNull();
        $t->index(['account_id', 'completed_at'], 'sapr_account_time');
        $t->foreign('account_id', 'sapr_account_fk')->references('sis_student_accounts', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve protected recovery receipts. Restore a verified backup instead.');
    }
}
