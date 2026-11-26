<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\WebsiteFormFixture;

final class WebsiteFormMigrationTest extends TestCase
{
    public function testStorageAndScopeSchemaCompileAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261126000002_CreateSisWebsiteFormInbox.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
            '"' . $name . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            foreach (['inbox', 'receipts', 'access'] as $method) {
                $table = new CreateTable('sis_website_form_' . $method);
                \CreateSisWebsiteFormInbox::$method($table);
                $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString('PRIMARY KEY', $sql);
                self::assertDoesNotMatchRegularExpression('/COLLATE|DROP |DELETE FROM|AUTO_INCREMENT/i', $sql);
            }
        }
    }

    public function testRetryPreservesInquiriesScopesReceiptsAndExistingPermissions(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
        $f = new WebsiteFormFixture();
        $f->submit();
        $tables = ['sis_website_form_inbox', 'sis_website_form_access', 'sis_website_form_receipts',
            'sis_access_permissions', 'sis_access_implications', 'sis_access_user_permissions', 'sis_history_events'];
        $before = array_map($f->sis->sql->rows(...), $tables);
        $migration = new \CreateSisWebsiteFormInbox('20261126000002');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->sis->connection, 'migration'),
        ]));
        $migration->up();
        self::assertSame($before, array_map($f->sis->sql->rows(...), $tables));
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function testNewDutiesHaveOnlySelfImplicationsAndNoAutomaticStaffGrants(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $s = new \SisFixture();
        foreach (['sis:website:forms:read', 'sis:website:forms:manage'] as $name) {
            $permission = $s->sql->one('sis_access_permissions', ['name' => $name]);
            self::assertSame(1, (int) $permission['is_system']);
            self::assertSame([], $s->sql->rows('sis_access_user_permissions', ['permission_id' => $permission['id']]));
            self::assertSame([], $s->sql->rows('sis_access_role_permissions', ['permission_id' => $permission['id']]));
            $links = $s->sql->rows('sis_access_implications', ['ancestor_id' => $permission['id']]);
            self::assertCount(1, $links);
            self::assertSame($permission['id'], $links[0]['descendant_id']);
        }
        self::assertSame([], $s->sql->rows('sis_website_form_access'));
    }
}
