<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisNotificationPreferences extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_notification_preferences", self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->foreign("id", "snp_person_fk")->references("sis_persons", "id");
        $t->integer("revision")->notNull();
        $t->integer("sound")->size("tiny")->notNull();
        $t->string("sound_id", 16)->notNull();
        $t->integer("keep_visible")->size("tiny")->notNull();
    }

    public function down(): void
    {
        $this->schema()->drop("sis_notification_preferences");
    }
}
