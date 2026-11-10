<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionVersions extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_section_versions', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('section_id', 26)->notNull();
            $t->integer('stream_version')->notNull();
            $t->integer('schema_version')->notNull();
            $t->string('course_id', 26)->notNull();
            $t->string('course_version_id', 26)->notNull();
            $t->string('term_id', 26)->notNull();
            $t->string('term_code', 80)->notNull();
            $t->date('term_starts_on')->notNull();
            $t->date('term_ends_on')->notNull();
            $t->string('code', 80)->notNull();
            $t->integer('capacity')->notNull();
            $t->string('faculty_user_id', 26);
            $t->string('faculty_person_id', 26);
            $t->string('delivery_mode', 20)->notNull();
            $t->integer('fee_minor')->notNull();
            $t->string('currency', 3)->notNull();
            $t->date('instruction_starts_on')->notNull();
            $t->date('instruction_ends_on')->notNull();
            $t->foreign('section_id', 'ssv_section_fk')->references('sis_sections', 'id');
            $t->unique(['section_id', 'stream_version'], 'ssv_section_version');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain section references; use a verified projection rebuild instead.');
    }
}
