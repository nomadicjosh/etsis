<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisSectionOfferingSelections extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_section_offering_versions', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('publication_id', 26)->notNull();
            $t->foreign('id', 'ssov_section_fk')->references('sis_sections', 'id');
            $t->foreign('publication_id', 'ssov_publication_fk')->references('sis_section_versions', 'id');
        });
        $this->schema()->create('sis_enrollment_section_versions', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('publication_id', 26)->notNull();
            // Restricted routing survives early-drop removal of the current enrollment/credit rows.
            $t->foreign('id', 'sesv_enrollment_fk')->references('sis_registration_streams', 'id');
            $t->foreign('publication_id', 'sesv_publication_fk')->references('sis_section_versions', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain offering and enrollment references; use verified reconstruction.');
    }
}
