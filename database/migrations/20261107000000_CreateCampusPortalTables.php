<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateCampusPortalTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_portal_audiences', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('post_id', 26)->notNull()->unique('spa_post');
            $table->string('audience', 8)->notNull();
            $table->string('published_at', 26);
            $table->foreign('post_id', 'spa_post_fk')->references('sis_portal_posts', 'id');
        });
        $this->schema()->create('sis_portal_contacts', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull()->unique('spc_person');
            $table->text('encrypted_contact')->notNull();
            $table->integer('version')->notNull();
            $table->foreign('person_id', 'spc_person_fk')->references('sis_persons', 'id');
        });
        $this->schema()->create('sis_transcript_requests', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->string('owner_user_id', 36)->notNull();
            $table->string('delivery', 12)->notNull();
            $table->text('encrypted_recipient')->notNull();
            $table->string('status', 16)->notNull();
            $table->string('requested_at', 26)->notNull();
            $table->integer('version')->notNull();
            $table->foreign('student_id', 'str_student_fk')->references('sis_students', 'id');
            $table->foreign('owner_user_id', 'str_owner_fk')->references('users', 'user_id');
        });
        $this->schema()->create('sis_portal_payments', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('account_id', 26)->notNull()->index('spp_account');
            $table->string('owner_user_id', 36)->notNull();
            $table->integer('amount_minor')->notNull();
            $table->string('currency', 3)->notNull();
            $table->string('status', 16)->notNull();
            $table->string('session_id', 191)->notNull()->unique('spp_session');
            $table->text('encrypted_url');
            $table->integer('expires_at')->size('big')->notNull();
            $table->foreign('account_id', 'spp_account_fk')->references('sis_student_accounts', 'id');
            $table->foreign('owner_user_id', 'spp_owner_fk')->references('users', 'user_id');
        });
        $adapter = $this->getAdapter();
        if (!$adapter instanceof \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter) {
            throw new LogicException('Portal migration requires a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new \Infrastructure\Persistence\SqlRecords($db);
        \Infrastructure\Persistence\PortableTransactions::run($db, static function () use ($sql): void {
            $existing = $sql->rows('sis_access_permissions', ['name' => 'sis:transcript:manage'], 1);
            $id = $existing[0]['id'] ?? \Domain\Shared\ValueObject\EntityId::generate()->value;
            if ($existing === []) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => 'sis:transcript:manage',
                    'description' => 'Process official transcript requests.', 'is_system' => 1]);
            } else {
                $sql->update('sis_access_permissions', ['is_system' => 1], ['id' => $id]);
            }
            $group = $sql->one('sis_access_permissions', ['name' => 'sis']);
            $ancestors = array_column(
                $sql->rows('sis_access_implications', ['descendant_id' => $group['id']]),
                'ancestor_id'
            );
            foreach (array_unique([$id, $group['id'], ...$ancestors]) as $ancestor) {
                $link = ['ancestor_id' => $ancestor, 'descendant_id' => $id];
                if ($sql->rows('sis_access_implications', $link, 1) === []) {
                    $sql->insert(
                        'sis_access_implications',
                        ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value] + $link
                    );
                }
            }
        });
    }

    public function down(): void
    {
        $tables = ['sis_portal_payments', 'sis_transcript_requests', 'sis_portal_contacts', 'sis_portal_audiences'];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
