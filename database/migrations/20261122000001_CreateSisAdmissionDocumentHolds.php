<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionDocumentHolds extends Migration
{
    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('person_id', 26)->notNull();
        $table->string('application_id', 26);
        $table->integer('version')->notNull();
        $table->integer('scope_sequence')->notNull();
        $table->integer('active')->notNull();
        $table->string('created_at', 32)->notNull();
        $table->string('released_at', 32);
        $table->string('release_operation_id', 26);
        $table->text('protected_metadata')->notNull();
        $table->index(['person_id', 'application_id', 'scope_sequence'], 'sadh_scope');
        $table->index(['person_id', 'id'], 'sadh_history');
        $table->index(['release_operation_id'], 'sadh_release');
        $table->foreign('person_id', 'sadh_person_fk')->references('sis_persons', 'id');
        $table->foreign('application_id', 'sadh_application_fk')->references('sis_applications', 'id');
    }

    public function up(): void
    {
        $this->schema()->create('sis_admission_document_holds', self::define(...));
    }

    public function down(): void
    {
        throw new LogicException('Document holds require an explicit institutional disposition plan.');
    }
}
