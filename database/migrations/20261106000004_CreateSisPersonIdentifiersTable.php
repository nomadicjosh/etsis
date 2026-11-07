<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonIdentifiersTable extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_person_identifiers', true)) {
            $this->schema()->create('sis_person_identifiers', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
                $table->string('person_id', 26)->notNull()->unique('sis_person_identity_owner');
                $table->string('institutional_id', 32)->notNull()->unique('sis_person_institutional_id');
                $table->integer('version')->notNull();
                $table->foreign('person_id', 'sis_identity_person_fk')->references('sis_persons', 'id');
            });
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Person identity migration requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $generator = new RandomInstitutionalIdGenerator();
        PortableTransactions::run($db, static function () use ($sql, $generator): void {
            // Finish this cursor before inserting, including on drivers without multiple active result sets.
            $id = $sql->quote('id');
            $personId = $sql->quote('person_id');
            $missing = $sql->fetch('SELECT p.' . $id . ' FROM ' . $sql->quote('sis_persons') . ' p LEFT JOIN '
                . $sql->quote('sis_person_identifiers') . ' i ON i.' . $personId . ' = p.' . $id
                . ' WHERE i.' . $personId . ' IS NULL ORDER BY p.' . $id);
            foreach ($missing as $row) {
                $value = null;
                for ($attempt = 0; $attempt < 8; ++$attempt) {
                    $candidate = $generator->next();
                    if ($sql->rows('sis_person_identifiers', ['institutional_id' => $candidate->value]) === []) {
                        $value = $candidate;
                        break;
                    }
                }
                if ($value === null) {
                    throw new RuntimeException('Unable to allocate a person institutional ID.');
                }
                $sql->insert('sis_person_identifiers', [
                    'id' => EntityId::generate()->value,
                    'person_id' => $row['id'],
                    'institutional_id' => $value->value,
                    'version' => 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_person_identifiers');
    }
}
