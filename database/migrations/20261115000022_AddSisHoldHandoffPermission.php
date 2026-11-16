<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisHoldHandoffPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Hold handoff setup requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = 'sis:restrictions:handoff';
            $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($old !== null && (int) $old['is_system'] !== 1) {
                throw new LogicException('Hold handoff conflicts with a custom permission.');
            }
            $id = $old['id'] ?? EntityId::generate()->value;
            if ($old === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Approve reviewed hold handoffs between authorized offices.', 'is_system' => 1]);
            }
            $sis = $sql->one('sis_access_permissions', ['name' => 'sis']);
            foreach ([$id, $sis['id']] as $ancestor) {
                $existing = $sql->rows('sis_access_implications', [
                    'ancestor_id' => $ancestor,
                    'descendant_id' => $id,
                ], 1);
                if ($existing === []) {
                    $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                        'ancestor_id' => $ancestor, 'descendant_id' => $id]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException(
            'Retain recorded handoff authority; permission removal requires explicit disposition.'
        );
    }
}
