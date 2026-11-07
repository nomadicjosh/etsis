<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentAccountsTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_student_accounts', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('currency', 3)->notNull();
            $table->unique(['student_id', 'term_id'], 'sis_account_student_term');
            $table->foreign('student_id', 'sis_account_student_fk')->references('sis_students', 'id');
            $table->foreign('term_id', 'sis_account_term_fk')->references('sis_terms', 'id');
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_student_accounts');
    }
}
