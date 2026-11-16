<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisDeliveryReviews extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_deliveries', static function (AlterTable $t): void {
            $t->string('disposition', 16);
            $t->string('resolution_id', 26);
            $t->text('encrypted_resolution');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retained delivery decisions need explicit data disposition before removal.');
    }
}
