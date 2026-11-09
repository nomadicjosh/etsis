<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionDeliveryTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_section_delivery", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("section_id", 26)->unique("ssd_section_unique")->notNull();
            $table->string("mode", 24)->notNull();
            $table->foreign("section_id", "ssd_section_fk")->references("sis_sections", "id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_section_delivery");
    }
}
