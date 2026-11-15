<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisHrDefinitions extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_hr_pay_grades', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('code', 40)->notNull()->unique('shpg_code');
            $t->string('title', 191)->notNull();
            $t->string('currency', 3)->notNull();
            $t->string('basis', 8)->notNull();
            $t->integer('minimum_minor')->notNull();
            $t->integer('maximum_minor')->notNull();
            $t->integer('active')->notNull();
            $t->integer('version')->notNull();
        });
        $this->schema()->create('sis_hr_jobs', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('code', 40)->notNull()->unique('shj_code');
            $t->string('title', 191)->notNull();
            $t->string('pay_grade_id', 26);
            $t->string('currency', 3)->notNull();
            $t->string('basis', 8)->notNull();
            $t->integer('rate_minor');
            $t->integer('weekly_minutes');
            $t->integer('active')->notNull();
            $t->integer('version')->notNull();
            $t->foreign('pay_grade_id', 'shj_grade_fk')->references('sis_hr_pay_grades', 'id');
            $t->index(['pay_grade_id', 'active'], 'shj_grade_active');
        });
    }
    public function down(): void
    {
        throw new LogicException('Retire definitions or arrange explicit data disposition before removing HR setup.');
    }
}
