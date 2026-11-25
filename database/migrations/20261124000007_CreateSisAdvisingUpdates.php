<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Infrastructure\Persistence\PostgresReferencedColumnCompiler;

final class CreateSisAdvisingUpdates extends Migration
{
    public function up(): void
    {
        if ($this->schema()->hasTable('sis_advising_updates', clear: true)) {
            return;
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $connection = $adapter->connection();
        if (!str_starts_with((string) $connection->getDsn(), 'pgsql:')) {
            $this->schema()->create('sis_advising_updates', self::define(...));
            return;
        }
        // Reuse the migration compiler that preserves converted PostgreSQL reference collations.
        $parents = ['person_id' => ['sis_persons', 'id'], 'student_id' => ['sis_students', 'id'],
            'pursuit_id' => ['sis_student_programs', 'id'], 'checkin_id' => ['sis_advising_checkins', 'id'],
            'owner_user_id' => ['sis_advising_checkins', 'owner_user_id']];
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
        $table = new CreateTable('sis_advising_updates');
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
        $t->string('owner_user_id', 36)->notNull();
        foreach (['person_id', 'student_id', 'pursuit_id', 'checkin_id'] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer('revision')->notNull();
        $t->string('status', 12)->notNull();
        $t->string('recorded_at', 27)->notNull();
        $t->string('read_at', 27);
        $t->unique(['checkin_id'], 'sadu_checkin_unique');
        $t->index(['owner_user_id', 'read_at', 'recorded_at', 'id'], 'sadu_owner_page');
        $t->foreign('person_id', 'sadu_person_fk')->references('sis_persons', 'id');
        $t->foreign('student_id', 'sadu_student_fk')->references('sis_students', 'id');
        $t->foreign('pursuit_id', 'sadu_pursuit_fk')->references('sis_student_programs', 'id');
        $t->foreign('checkin_id', 'sadu_checkin_fk')->references('sis_advising_checkins', 'id');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_advising_updates');
    }
}
