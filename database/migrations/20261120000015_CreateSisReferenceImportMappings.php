<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisReferenceImportMappings extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_subject_import_mappings", clear: true)) {
            $this->schema()->create("sis_subject_import_mappings", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("source", 80)->notNull();
                $t->string("external_id", 80)->notNull();
                $t->string("content_hash", 64)->notNull();
                $t->string("subject_id", 26)->notNull();
                $t->unique(["source", "external_id"], "ssim_source_unique");
                $t->foreign("subject_id", "ssim_subject_fk")->references("sis_subjects", "id");
            });
        }
        if (!$this->schema()->hasTable("sis_class_import_mappings", clear: true)) {
            $this->schema()->create("sis_class_import_mappings", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("source", 80)->notNull();
                $t->string("external_id", 80)->notNull();
                $t->string("content_hash", 64)->notNull();
                $t->string("classification_id", 26)->notNull();
                $t->string("kind", 40)->notNull();
                $t->unique(["source", "kind", "external_id"], "scim_source_unique");
                $t->foreign("classification_id", "scim_class_fk")->references(
                    "sis_catalog_classifications",
                    "id",
                );
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Preserve catalog reference source identities and retry reconciliation.");
    }
}
