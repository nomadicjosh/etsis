<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWebsiteNewsletters extends Migration
{
    public function up(): void
    {
        foreach (
            ['sis_website_newsletters' => self::connections(...),
            'sis_website_subscriptions' => self::subscriptions(...)] as $name => $definition
        ) {
            if (!$this->schema()->hasTable($name, clear: true)) {
                $this->schema()->create($name, $definition);
            }
        }
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Newsletter connections require database storage.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = 'sis:website:forms:integrations';
            $old = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($old !== null && (int) $old['is_system'] !== 1) {
                throw new LogicException('Conflicting protected newsletter permission.');
            }
            $id = $old['id'] ?? EntityId::generate()->value;
            if ($old === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name, 'is_system' => 1]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
        });
    }

    public static function connections(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('name', 120)->notNull();
        $t->string('provider', 12)->notNull();
        $t->integer('enabled')->size('tiny')->notNull();
        $t->integer('version')->notNull();
        $t->string('target_hash', 64)->notNull();
        $t->text('sealed_data')->size('medium')->notNull();
    }

    public static function subscriptions(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('connection_id', 26)->notNull();
        $t->string('target_hash', 64)->notNull();
        $t->string('state', 16)->notNull();
        $t->string('issue', 32)->notNull();
        $t->integer('attempts')->notNull();
        $t->integer('version')->notNull();
        $t->integer('next_at')->size('big')->notNull();
        $t->integer('lease_until')->size('big')->notNull();
        $t->index(['state', 'next_at', 'id'], 'sws_due_idx');
    }

    public function down(): void
    {
        throw new LogicException('Review newsletter connections and pending delivery before removing storage.');
    }
}
