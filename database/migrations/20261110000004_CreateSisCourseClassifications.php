<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseClassifications extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_catalog_classifications", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("kind", 24)->notNull();
            $table->string("code", 40)->notNull();
            $table->string("title", 191)->notNull();
            $table->boolean("active")->notNull()->defaultValue(true);
            $table->unique(["kind", "code"], "catalog_class_kind_code_uq");
        });
        $this->schema()->create("sis_course_classifications", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->foreign("id", "course_class_course_fk")->references("sis_courses", "id");
            $kinds = ["department", "academic_level", "course_level"];
            foreach ($kinds as $kind) {
                $table->string($kind . "_id", 26);
                $table
                    ->foreign($kind . "_id", "course_class_" . $kind . "_fk")
                    ->references("sis_catalog_classifications", "id");
            }
        });
        $this->schema()->create("sis_course_version_classes", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->foreign("id", "course_vclass_version_fk")->references("sis_course_versions", "id");
            $kinds = ["department", "academic_level", "course_level"];
            foreach ($kinds as $kind) {
                $table->string($kind . "_id", 26);
                $table->string($kind . "_code", 40);
                $table->string($kind . "_title", 191);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve published course classifications and retained academic references.",
        );
    }
}
