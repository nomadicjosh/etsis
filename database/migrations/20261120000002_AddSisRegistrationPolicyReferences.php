<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisRegistrationPolicyReferences extends Migration
{
    public function up(): void
    {
        $columns = ['publication_id' => 26, 'definition_hash' => 64, 'policy_version' => 0];
        foreach ($columns as $column => $size) {
            if (
                !in_array(
                    $column,
                    $this->schema()->getColumns("sis_reg_windows", clear: true),
                    true,
                )
            ) {
                $this->schema()->alter("sis_reg_windows", static function (
                    AlterTable $table,
                ) use (
                    $column,
                    $size,
                ): void {
                    if ($size === 0) {
                        $table->integer($column);
                    } else {
                        $table->string($column, $size);
                    }
                });
            }
        }
        if (
            !in_array(
                "web_registration",
                $this->schema()->getColumns(
                    "sis_section_versions",
                    clear: true,
                ),
                true,
            )
        ) {
            $this->schema()->alter("sis_section_versions", static function (
                AlterTable $table,
            ): void {
                $table->integer("web_registration");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve recorded registration-policy references and section facts.",
        );
    }
}
