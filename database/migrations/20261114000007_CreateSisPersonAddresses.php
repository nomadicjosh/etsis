<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonAddresses extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_person_addresses', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('person_id', 26)->notNull()->unique('spa_person');
            $t->text('encrypted_addresses')->size('medium')->notNull();
            $t->integer('version')->notNull();
            $t->foreign('person_id', 'spa_person_fk')->references('sis_persons', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Address projections require explicit disposition and replay planning.');
    }
}
