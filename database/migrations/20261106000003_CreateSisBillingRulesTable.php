<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBillingRulesTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_billing_rules', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('program_id', 26);
            $table->foreign('term_id', 'sis_billing_rule_term')->references('sis_terms', 'id');
            $table->foreign('program_id', 'sis_billing_rule_prog')->references('sis_programs', 'id');
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_billing_rules');
    }
}
