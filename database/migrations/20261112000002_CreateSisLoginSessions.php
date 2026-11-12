<?php
declare(strict_types=1);
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
final class CreateSisLoginSessions extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_login_sessions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("user_id", 26)->notNull();
            $t->string("cookie_hash", 64)->notNull()->unique("login_cookie_hash_uq");
            $t->string("account_revision", 64)->notNull();
            $t->string("expires_at", 32)->notNull();
            $t->string("observed_at", 32)->notNull();
            $t->integer("revoked")->notNull()->defaultValue(0);
            $t->string("revoked_at", 32);
            $t->index(["user_id", "expires_at"], "login_user_expiry_idx");
            $t->index(["expires_at", "id"], "login_retention_idx");
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain active sign-in references and revocation tombstones until their expiry.",
        );
    }
}
