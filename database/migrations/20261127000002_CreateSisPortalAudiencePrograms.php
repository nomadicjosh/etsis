<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPortalAudiencePrograms extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_portal_audience_programs')) {
            $this->schema()->create('sis_portal_audience_programs', self::define(...));
        }
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('post_id', 26)->notNull();
        $table->string('program_id', 26)->notNull();
        $table->string('program_name', 300)->notNull();
        $table->unique(['post_id', 'program_id'], 'spap_post_program');
        $table->foreign('post_id', 'spap_post_fk')->references('sis_portal_posts', 'id');
        // Retain the selected identity/label after program removal; readers require a current program.
    }

    public function down(): void
    {
        $this->schema()->drop('sis_portal_audience_programs');
    }
}
