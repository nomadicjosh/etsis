<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisBookingUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
            $t->integer('notice_version');
            $t->string('notice_kind', 24);
            $t->string('notice_at', 27);
            $t->string('notice_occurrence_id', 26);
            $t->string('notice_related_id', 26);
            $t->integer('seen_notice_version');
        });
        $this->schema()->alter('sis_bookings', static function (AlterTable $t): void {
            $t->index(['owner_user_id', 'notice_at', 'id'], 'sb_owner_updates');
        });
    }

    public function down(): void
    {
        throw new LogicException('Owner update metadata requires explicit disposition before removal.');
    }
}
