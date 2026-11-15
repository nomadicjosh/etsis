<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisUserSignInStatus extends Migration
{
    public function up(): void
    {
        // Preserve the existing sign-in policy. No historical access decisions are fabricated.
        $this->schema()->alter('users', static function (AlterTable $t): void {
            $t->integer('sign_in_enabled')->notNull()->defaultValue(1);
        });
    }
    public function down(): void
    {
        throw new LogicException('Retain sign-in decisions and their protected projection.');
    }
}
