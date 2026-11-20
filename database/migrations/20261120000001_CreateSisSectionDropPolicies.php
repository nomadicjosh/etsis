<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionDropPolicies extends Migration
{
    public function up(): void
    {
        if ($this->schema()->hasTable('sis_section_drop_policies')) {
            return;
        }
        $this->schema()->create('sis_section_drop_policies', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('section_id', 26)->notNull();
            $table->integer('version')->notNull();
            $table->text('windows')->notNull();
            $table->string('publication_id', 26)->notNull();
            $table->text('calendar_snapshot')->notNull();
            $table->unique(['section_id'], 'ssdp_section_uq');
            $table->foreign('section_id', 'ssdp_section_fk')->references('sis_sections', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Retain section policy publications and their reconstructible references.');
    }
}
