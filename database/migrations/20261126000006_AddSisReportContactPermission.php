<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisReportContactPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Reporting contact access requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = 'sis:reporting:contact';
            $permission = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission['is_system'] !== 1) {
                throw new LogicException('Conflicting reporting contact permission.');
            }
            $id = $permission['id'] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert('sis_access_permissions', [
                    'id' => $id, 'name' => $name,
                    'description' => 'Include current linked-account contact email in student-related reports.',
                    'is_system' => 1,
                ]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', [
                    'id' => EntityId::generate()->value, 'ancestor_id' => $id, 'descendant_id' => $id,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve reporting contact authority and explicitly review any existing grants.');
    }
}
