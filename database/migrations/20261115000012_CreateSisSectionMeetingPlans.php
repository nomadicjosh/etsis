<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionMeetingPlans extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_section_meeting_plans', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('publication_id', 26)->notNull();
            $t->integer('version')->notNull();
            $t->string('definition_hash', 64)->notNull();
            $t->unique(['publication_id'], 'ssmp_publication_uq');
            $t->foreign('id', 'ssmp_section_fk')->references('sis_sections', 'id');
        });
        $this->schema()->alter('sis_booking_slots', static function (AlterTable $t): void {
            $t->index(['booking_id', 'cancelled_at', 'starts_at', 'id'], 'sbs_booking_active');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve published meeting-plan references; downgrade requires explicit conversion.');
    }
}
