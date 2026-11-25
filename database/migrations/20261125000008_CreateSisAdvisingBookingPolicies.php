<?php

declare(strict_types=1);

use Domain\Advising\Service\BookingPolicyService;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvisingBookingPolicies extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_advising_booking_policies', clear: true)) {
            $this->schema()->create('sis_advising_booking_policies', self::define(...));
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Booking policy requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $old = $sql->rows('sis_access_permissions', ['name' => BookingPolicyService::PERMISSION], 1)[0] ?? null;
            if ($old !== null && (int) $old['is_system'] !== 1) {
                throw new LogicException('Conflicting appointment booking policy permission.');
            }
            $id = $old['id'] ?? EntityId::generate()->value;
            if ($old === null) {
                $sql->insert('sis_access_permissions', ['id' => $id,
                    'name' => BookingPolicyService::PERMISSION, 'is_system' => 1]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
        });
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('revision')->notNull()->unique('sabp_revision');
        $t->text('sealed_data')->size('medium')->notNull();
    }

    public function down(): void
    {
        throw new LogicException('Preserve accepted booking policy revisions and original receipts.');
    }
}
