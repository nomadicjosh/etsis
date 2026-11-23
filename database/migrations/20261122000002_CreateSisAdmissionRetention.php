<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionRetention extends Migration
{
    public static function policies(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->integer('revision')->notNull()->unique('sarp_revision');
        $table->string('created_at', 32)->notNull();
        $table->text('protected_metadata')->size('big')->notNull();
    }

    public static function classifications(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        foreach (['document_id', 'application_id', 'person_id'] as $field) {
            $table->string($field, 26)->notNull();
        }
        $table->integer('document_version')->notNull();
        $table->string('recorded_at', 32)->notNull();
        $table->text('protected_metadata')->size('big')->notNull();
        $table->index(['document_id', 'id'], 'sadc_document');
        $table->foreign('document_id', 'sadc_document_fk')->references('sis_admission_documents', 'id');
        $table->foreign('application_id', 'sadc_application_fk')->references('sis_applications', 'id');
        $table->foreign('person_id', 'sadc_person_fk')->references('sis_persons', 'id');
    }

    public function up(): void
    {
        $this->schema()->create('sis_admission_retention_policies', self::policies(...));
        $this->schema()->create('sis_admission_document_categories', self::classifications(...));
    }

    public function down(): void
    {
        throw new LogicException('Recorded retention bases require an institutional disposition plan.');
    }
}
