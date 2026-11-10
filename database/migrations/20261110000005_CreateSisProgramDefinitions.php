<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramDefinitions extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_program_details", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->text("description");
            $table->foreign("id", "program_details_owner_fk")->references("sis_programs", "id");
            $kinds = ["department", "academic_level", "award"];
            foreach ($kinds as $kind) {
                $table->string($kind . "_id", 26);
                $table
                    ->foreign($kind . "_id", "prog_detail_" . $kind . "_fk")
                    ->references("sis_catalog_classifications", "id");
            }
        });
        $this->schema()->create("sis_program_catalog_details", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("program_id", 26)->notNull();
            $table->string("academic_year_code", 9)->notNull();
            $table->integer("stream_version")->notNull();
            $table->text("description");
            $table->unique(["program_id", "stream_version"], "program_pub_position_uq");
            $table->foreign("id", "program_pub_catalog_fk")->references("sis_program_catalogs", "id");
            $table->foreign("program_id", "program_pub_owner_fk")->references("sis_programs", "id");
            $kinds = ["department", "academic_level", "award"];
            foreach ($kinds as $kind) {
                $table->string($kind . "_id", 26);
                $table->string($kind . "_code", 40);
                $table->string($kind . "_title", 191);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve published program definitions and cohort references.");
    }
}
