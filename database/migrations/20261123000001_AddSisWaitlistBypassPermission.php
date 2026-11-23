<?php

declare(strict_types=1);

use Domain\Registration\Waitlist\WaitlistBypass;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisWaitlistBypassPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Waitlist bypass authority needs a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $row = $sql->rows('sis_access_permissions', ['name' => WaitlistBypass::DUTY], 1)[0] ?? null;
            if ($row !== null && (int) $row['is_system'] !== 1) {
                throw new LogicException('Conflicting waitlist-bypass permission.');
            }
            $id = $row['id'] ?? EntityId::generate()->value;
            if ($row === null) {
                $sql->insert('sis_access_permissions', [
                    'id' => $id,
                    'name' => WaitlistBypass::DUTY,
                    'description' =>
                        'Review one later waitlist request and explicitly register it ahead of earlier requests.',
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
        throw new LogicException('Preserve waitlist-bypass authority and its historical grants.');
    }
}
