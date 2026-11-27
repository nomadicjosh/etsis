<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRegistrationOpenStates extends Migration
{
    public function up(): void
    {
        foreach (['reads', 'dismissals'] as $kind) {
            $name = 'sis_registration_open_' . $kind;
            if (!$this->schema()->hasTable($name)) {
                $this->schema()->create($name, static fn (CreateTable $table) => self::define($table, $kind));
            }
        }
    }

    public static function define(CreateTable $table, string $kind): void
    {
        $prefix = $kind === 'reads' ? 'rgo_read' : 'rgo_dismiss';
        $table->string('id', 26)->primary()->notNull();
        $table->string('owner_user_id', 26)->notNull();
        $table->string('person_id', 26)->notNull();
        $table->string('term_id', 26)->notNull();
        $table->string('publication_id', 26)->notNull();
        $table->integer('revision')->notNull();
        if ($kind === 'reads') {
            $table->string('read_at', 27)->notNull();
        }
        $table->unique(['owner_user_id', 'person_id', 'term_id'], $prefix . '_owner_uq');
        $table->foreign('owner_user_id', $prefix . '_user_fk')->references('users', 'user_id')->onDelete('CASCADE');
        $table->foreign('person_id', $prefix . '_person_fk')->references('sis_persons', 'id')->onDelete('CASCADE');
        $table->foreign('term_id', $prefix . '_term_fk')->references('sis_terms', 'id')->onDelete('CASCADE');
    }

    public function down(): void
    {
        throw new LogicException('Review current registration-notice reading and deletion state before removing it.');
    }
}
