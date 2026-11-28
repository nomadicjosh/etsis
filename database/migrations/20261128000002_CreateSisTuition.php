<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTuition extends Migration
{
    public function up(): void
    {
        foreach (["schedules", "assignments", "bills", "invoices"] as $kind) {
            $name = "sis_tuition_" . $kind;
            if (!$this->schema()->hasTable($name, clear: true)) {
                $this->schema()->create($name, [self::class, $kind]);
            }
        }
    }
    public static function schedules(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("name", 191)->notNull();
        $t->string("currency", 3)->notNull();
        self::head($t);
        $t->index(["name", "id"], "tuition_sched_page");
    }
    public static function assignments(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("scope", 16)->notNull();
        $t->string("scope_id", 26)->notNull();
        $t->string("schedule_id", 26);
        self::head($t);
        $t->unique(["scope", "scope_id"], "tuition_assign_scope");
    }
    public static function bills(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("student_id", 26)->notNull();
        $t->string("term_id", 26)->notNull();
        $t->string("currency", 3)->notNull();
        $t->integer("pending_count")->notNull();
        self::head($t);
        $t->unique(["student_id", "term_id"], "tuition_bill_student_term");
        $t->index(["pending_count", "id"], "tuition_bill_page");
    }
    public static function invoices(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("bill_id", 26)->notNull();
        $t->string("reference", 80)->notNull();
        $t->string("issued_at", 20)->notNull();
        $t->unique(["reference"], "tuition_invoice_ref");
        $t->index(["bill_id", "id"], "tuition_invoice_bill");
    }
    private static function head(CreateTable $t): void
    {
        $t->integer("version")->notNull();
        $t->string("event_id", 26)->notNull();
        $t->string("source_hash", 64)->notNull();
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve tuition publications, assignments, pending decisions and issued invoices.",
        );
    }
}
