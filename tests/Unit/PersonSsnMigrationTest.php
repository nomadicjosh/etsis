<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\PersonSsnFixture;

final class PersonSsnMigrationTest extends TestCase
{
    public function testMaskedProjectionSchemaCompilesAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261126000001_CreateSisPersonSsns.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $n): string => '"' . $n . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_person_ssns');
            \CreateSisPersonSsns::define($table);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', $sql);
            self::assertStringContainsString('sealed_data', $sql);
            self::assertDoesNotMatchRegularExpression('/COLLATE|DROP |DELETE FROM|AUTO_INCREMENT/i', $sql);
        }
    }

    public function testRerunPreservesAcceptedHistoryMasksAndGrants(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonSsnFixture.php';
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $before = $f->facts();
        $migration = new \CreateSisPersonSsns('20261126000001');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->sis->connection, 'migration'),
        ]));
        $migration->up();
        self::assertSame($before, $f->facts());
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function testNewDefinitionsDoNotGrantOrdinaryStaffOrImplyCorrectionAuthority(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $f = new \SisFixture();
        foreach (['sis:person:ssn:read', 'sis:person:ssn:manage'] as $name) {
            $permission = $f->sql->one('sis_access_permissions', ['name' => $name]);
            self::assertSame(1, (int) $permission['is_system']);
            self::assertSame([], $f->sql->rows('sis_access_user_permissions', ['permission_id' => $permission['id']]));
            self::assertSame([], $f->sql->rows('sis_access_role_permissions', ['permission_id' => $permission['id']]));
            $links = $f->sql->rows('sis_access_implications', ['ancestor_id' => $permission['id']]);
            self::assertCount(1, $links);
            self::assertSame($permission['id'], $links[0]['descendant_id']);
        }
    }
}
