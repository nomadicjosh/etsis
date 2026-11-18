<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionClassifications extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_section_classifications", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->foreign("id", "ssc_section_fk")->references(
                "sis_sections",
                "id",
            );
            $kinds = ["instructional_method", "campus"];
            foreach ($kinds as $kind) {
                $t->string($kind . "_id", 26);
                $t->foreign($kind . "_id", "ssc_" . $kind . "_fk")->references(
                    "sis_catalog_classifications",
                    "id",
                );
            }
        });
        $this->schema()->create("sis_section_version_classes", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->foreign("id", "ssvc_version_fk")->references(
                "sis_section_versions",
                "id",
            );
            $kinds = ["instructional_method", "campus"];
            foreach ($kinds as $kind) {
                $t->string($kind . "_id", 26);
                $t->string($kind . "_code", 40);
                $t->string($kind . "_title", 191);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain published section classifications; plan disposition explicitly.",
        );
    }
}
