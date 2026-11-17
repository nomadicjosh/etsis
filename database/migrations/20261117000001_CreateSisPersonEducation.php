<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonEducation extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_person_education', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull()->unique('spe_person');
            $table->text('encrypted_background')->size('medium')->notNull();
            $table->integer('version')->notNull();
            $table->foreign('person_id', 'spe_person_fk')->references('sis_persons', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Educational-background projections require explicit retention and replay planning.');
    }
}
