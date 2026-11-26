<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonSsns extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_person_ssns', clear: true)) {
            $this->schema()->create('sis_person_ssns', self::define(...));
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('SSN storage requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            foreach (['sis:person:ssn:read', 'sis:person:ssn:manage'] as $name) {
                $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
                if ($old !== null && (int) $old['is_system'] !== 1) {
                    throw new LogicException('Conflicting protected SSN permission.');
                }
                $id = $old['id'] ?? EntityId::generate()->value;
                if ($old === null) {
                    $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name, 'is_system' => 1]);
                }
                if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                    $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                        'ancestor_id' => $id, 'descendant_id' => $id]);
                }
            }
        });
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('version')->notNull();
        $t->text('sealed_data')->notNull();
        $t->foreign('id', 'pssn_person_fk')->references('sis_persons', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded SSN facts until institution-approved disposition.');
    }
}
