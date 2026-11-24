<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateCampusAnnouncementDismissals extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_announcement_dismissals', clear: true)) {
            $this->schema()->create('sis_announcement_dismissals', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('owner_user_id', 36)->notNull();
        $t->string('post_id', 26)->notNull();
        $t->integer('revision')->notNull();
        $t->unique(['owner_user_id', 'post_id'], 'sad_owner_post');
        $t->foreign('owner_user_id', 'sad_owner_fk')->references('users', 'user_id');
        $t->foreign('post_id', 'sad_post_fk')->references('sis_portal_posts', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Removing suppression markers would redeliver deleted notifications.');
    }
}
