<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWebsiteFormInbox extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_website_form_inbox', clear: true)) {
            $this->schema()->create('sis_website_form_inbox', self::inbox(...));
        }
        if (!$this->schema()->hasTable('sis_website_form_receipts', clear: true)) {
            $this->schema()->create('sis_website_form_receipts', self::receipts(...));
        }
        if (!$this->schema()->hasTable('sis_website_form_access', clear: true)) {
            $this->schema()->create('sis_website_form_access', self::access(...));
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Website forms require a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            foreach (['sis:website:forms:read', 'sis:website:forms:manage'] as $name) {
                $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
                if ($old !== null && (int) $old['is_system'] !== 1) {
                    throw new LogicException('Conflicting protected website form permission.');
                }
                $id = $old['id'] ?? EntityId::generate()->value;
                if ($old === null) {
                    $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name, 'is_system' => 1]);
                }
                if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                    $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                        'ancestor_id' => $id, 'descendant_id' => $id]);
                }
            }
        });
    }

    public static function inbox(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('page_id', 191)->notNull();
        $t->string('form_key', 40)->notNull();
        $t->string('department_id', 26);
        $t->string('page_title', 191)->notNull();
        $t->string('form_title', 120)->notNull();
        $t->string('submitted_at', 20)->notNull();
        $t->string('status', 8)->notNull();
        $t->integer('version')->notNull();
        $t->text('sealed_data')->size('medium')->notNull();
        $t->index(['status', 'id'], 'swfi_status_idx');
        $t->index(['department_id', 'id'], 'swfi_department_idx');
        $t->index(['page_id', 'form_key', 'id'], 'swfi_form_idx');
    }

    public static function access(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('scope_kind', 12)->notNull();
        $t->string('scope_id', 191)->notNull();
        $t->string('form_key', 40)->notNull();
        $t->string('user_id', 26)->notNull();
        $t->integer('version')->notNull();
        $t->unique(['scope_kind', 'scope_id', 'form_key', 'user_id'], 'swfa_scope_user_uq');
        $t->index(['user_id', 'scope_kind', 'scope_id'], 'swfa_user_idx');
    }

    public static function receipts(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('page_id', 191)->notNull();
        $t->string('session_hash', 64)->notNull();
        $t->string('intent_hash', 64)->notNull();
        $t->string('accepted_at', 20)->notNull();
        $t->string('success_message', 500)->notNull();
        $t->index(['page_id', 'session_hash', 'accepted_at'], 'swfr_rate_idx');
        $t->index(['accepted_at'], 'swfr_prune_idx');
    }

    public function down(): void
    {
        throw new LogicException('Review website inquiries before removing their encrypted storage.');
    }
}
