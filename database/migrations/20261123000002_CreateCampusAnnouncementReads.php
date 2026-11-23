<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateCampusAnnouncementReads extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_announcement_reads', self::define(...));
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('owner_user_id', 36)->notNull()->index('sar_owner');
        $table->string('post_id', 26)->notNull();
        $table->integer('revision')->notNull();
        $table->string('read_at', 27)->notNull();
        $table->unique(['owner_user_id', 'post_id'], 'sar_owner_post');
        $table->foreign('owner_user_id', 'sar_owner_fk')->references('users', 'user_id');
        $table->foreign('post_id', 'sar_post_fk')->references('sis_portal_posts', 'id');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_announcement_reads');
    }
}
