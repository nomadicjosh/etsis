<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\NewsletterFixture;

final class NewsletterMigrationTest extends TestCase
{
    public function testReferenceOnlyOutboxAndConnectionSchemaCompileAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261126000003_CreateSisWebsiteNewsletters.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
            '"' . $name . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            foreach (['connections', 'subscriptions'] as $method) {
                $table = new CreateTable('sis_website_' . $method);
                \CreateSisWebsiteNewsletters::$method($table);
                $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString('PRIMARY KEY', $sql);
                self::assertDoesNotMatchRegularExpression('/COLLATE|DROP |DELETE FROM|AUTO_INCREMENT/i', $sql);
                if ($method === 'subscriptions') {
                    self::assertDoesNotMatchRegularExpression('/email|name|secret|answers|contact_data/i', $sql);
                }
            }
        }
    }

    public function testRetryPreservesQueuedConsentsCredentialsAndGrantsWithoutGrantingNewAuthority(): void
    {
        require_once dirname(__DIR__) . '/Support/NewsletterFixture.php';
        $f = new NewsletterFixture();
        $f->submit();
        $s = $f->forms->sis;
        $tables = ['sis_website_newsletters', 'sis_website_subscriptions', 'sis_website_form_inbox',
            'sis_access_permissions', 'sis_access_user_permissions', 'sis_access_role_permissions',
                'sis_history_events'];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \CreateSisWebsiteNewsletters('20261126000003');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $s->connection,
            'migration'
        )]));
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $permission = $s->sql->one('sis_access_permissions', ['name' => 'sis:website:forms:integrations']);
        self::assertSame([], $s->sql->rows('sis_access_role_permissions', ['permission_id' => $permission['id']]));
        self::assertCount(1, $s->sql->rows('sis_access_implications', ['ancestor_id' => $permission['id']]));
    }
}
