<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvisingAvailability extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_advising_availability', clear: true)) {
            $this->schema()->create('sis_advising_availability', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('host_person_id', 26)->notNull();
        $t->string('host_user_id', 36)->notNull();
        $t->string('status', 12)->notNull();
        $t->integer('version')->notNull();
        $t->string('starts_at', 20)->notNull();
        $t->string('ends_at', 20)->notNull();
        $t->text('sealed_data')->size('medium')->notNull();
        $t->index(['host_person_id', 'status', 'starts_at', 'id'], 'sava_host_time');
        $t->index(['host_person_id', 'id'], 'sava_host_page');
        $t->foreign('host_person_id', 'sava_host_fk')->references('sis_persons', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve availability and its original receipts until institutional disposition.');
    }
}
