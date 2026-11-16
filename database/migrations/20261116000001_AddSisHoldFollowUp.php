<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisHoldFollowUp extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Hold follow-up setup requires a database connection.');
        }
        if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // SQLite accepts a nullable foreign key inline without rebuilding recorded routing.
            $adapter->connection()->command('ALTER TABLE "sis_restriction_offices" '
                . 'ADD COLUMN "assigned_user_id" VARCHAR(36) REFERENCES "users" ("user_id")');
        } else {
            $this->schema()->alter('sis_restriction_offices', static function (AlterTable $t): void {
                $t->string('assigned_user_id', 36);
                $t->foreign('assigned_user_id', 'sro_assignee_fk')->references('users', 'user_id');
            });
        }
        $this->schema()->alter('sis_restriction_offices', static function (AlterTable $t): void {
            $t->string('follow_up_on', 10);
            $t->integer('work_revision')->notNull()->defaultValue(0);
            $t->index(['office_id', 'assigned_user_id', 'id'], 'sro_assigned_work');
            $t->index(['office_id', 'follow_up_on', 'id'], 'sro_due_work');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain office follow-up metadata; removal requires explicit disposition.');
    }
}
