<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisEmailVerifications extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_email_verifications')) {
            $this->schema()->create('sis_email_verifications', self::define(...));
        }
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('person_id', 26)->notNull();
        $table->integer('version')->notNull();
        $table->string('token_hash', 64);
        $table->string('requested_at', 20)->notNull();
        $table->string('expires_at', 20)->notNull();
        $table->string('state', 16)->notNull();
        $table->text('sealed_challenge');
        $table->text('sealed_notice');
        $table->text('sealed_verification');
        $table->index(['state', 'expires_at', 'id'], 'sev_pending_idx');
        $table->index(['expires_at', 'id'], 'sev_expiry_idx');
        $table->foreign('id', 'sev_user_fk')->references('users', 'user_id')->onDelete('CASCADE');
    }

    public function down(): void
    {
        throw new LogicException('Review account verification state before removing its protected storage.');
    }
}
