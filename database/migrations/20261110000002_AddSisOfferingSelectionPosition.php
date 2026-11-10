<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisOfferingSelectionPosition extends Migration
{
    public function up(): void
    {
        // The previous contract allowed exactly one initial selection. Its recorded position is one.
        $this->schema()->alter('sis_section_offering_versions', static function (AlterTable $t): void {
            $t->integer('stream_version')->notNull()->defaultValue(1);
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain offering selection concurrency positions and recorded decisions.');
    }
}
