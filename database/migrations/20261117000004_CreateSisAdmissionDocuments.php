<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionDocuments extends Migration
{
    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        foreach (['application_id', 'person_id', 'uploaded_by'] as $field) {
            $table->string($field, 26)->notNull();
        }
        $table->integer('version')->notNull();
        $table->integer('held')->notNull();
        $table->string('status', 20)->notNull();
        foreach (['created_at', 'expires_at', 'scan_after'] as $field) {
            $table->string($field, 32)->notNull();
        }
        $table->string('scan_token', 26);
        $table->text('protected_metadata')->notNull();
        $table->index(['application_id', 'id'], 'sad_application');
        $table->index(['person_id', 'status'], 'sad_person_quota');
        $table->index(['status', 'scan_after', 'id'], 'sad_scan_work');
        $table->foreign('application_id', 'sad_application_fk')->references('sis_applications', 'id');
        $table->foreign('person_id', 'sad_person_fk')->references('sis_persons', 'id');
    }
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Admission documents require a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $name = 'sis:admissions:documents:retain';
        $permission = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
        if ($permission !== null && (int) $permission['is_system'] !== 1) {
            throw new LogicException('The document-retention capability conflicts with a custom permission.');
        }
        $this->schema()->create('sis_admission_documents', self::define(...));
        PortableTransactions::run($db, static function () use ($sql, $permission, $name): void {
            $id = $permission['id'] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Manage admissions document access, holds, retention and reviewed disposal.',
                    'is_system' => 1]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
            // No automatic role grants or broad SIS implication. Super remains unrestricted.
        });
    }
    public function down(): void
    {
        throw new LogicException('Protected admission documents require an explicit disposition plan.');
    }
}
