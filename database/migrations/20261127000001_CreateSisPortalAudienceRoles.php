<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPortalAudienceRoles extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_portal_audience_roles')) {
            $this->schema()->create('sis_portal_audience_roles', self::define(...));
        }
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('post_id', 26)->notNull();
        $table->string('role_id', 26)->notNull();
        $table->string('role_name', 191)->notNull();
        $table->unique(['post_id', 'role_id'], 'spar_post_role');
        $table->foreign('post_id', 'spar_post_fk')->references('sis_portal_posts', 'id');
        // Retain the selected identity/label if an administrator later removes that role.
        // Membership queries require a current role; deletion never widens the audience.
    }

    public function down(): void
    {
        $this->schema()->drop('sis_portal_audience_roles');
    }
}
