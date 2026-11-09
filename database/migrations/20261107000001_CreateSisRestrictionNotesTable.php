<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRestrictionNotesTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_restriction_notes', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('restriction_id', 26)->notNull()->index('srn_restriction');
            $table->string('actor_id', 36)->notNull();
            $table->text('encrypted_content')->notNull();
            $table->string('created_at', 26)->notNull();
            $table->foreign('restriction_id', 'srn_restriction_fk')->references('sis_restrictions', 'id');
            $table->foreign('actor_id', 'srn_actor_fk')->references('users', 'user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_restriction_notes');
    }
}
