<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class CreateSisAcademicPostingTerms extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Academic placement authority requires a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = "sis:academic:placement:amend";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Academic placement authority conflicts with a custom permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Correct academic posting terms on institutional credits.",
                    "is_system" => 1,
                ]);
            }
            if (
                $sql->rows("sis_access_implications", ["ancestor_id" => $id, "descendant_id" => $id], 1) ===
                []
            ) {
                $sql->insert("sis_access_implications", [
                    "id" => EntityId::generate()->value,
                    "ancestor_id" => $id,
                    "descendant_id" => $id,
                ]);
            }
            // Independent duty: do not change existing role/user grants.
        });
        if (!$this->schema()->hasTable("sis_credit_posting_terms", clear: true)) {
            $this->schema()->create("sis_credit_posting_terms", static function (
                \Qubus\Expressive\Schema\CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("term_id", 26)->notNull();
                $t->string("term_code", 96)->notNull();
                $t->string("decision_id", 26)->notNull();
                $t->foreign("id", "scpt_credit_fk")
                    ->references("sis_academic_credits", "id")
                    ->onDelete("CASCADE");
                $t->foreign("term_id", "scpt_term_fk")->references("sis_terms", "id");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain the protected academic placement permission and its decision references.",
        );
    }
}
