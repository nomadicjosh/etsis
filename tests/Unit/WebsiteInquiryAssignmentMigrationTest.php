<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\WebsiteFormFixture;

final class WebsiteInquiryAssignmentMigrationTest extends TestCase
{
    public function testCurrentAssignmentStorageCompilesOnAllFiveDialectsWithoutBusinessBackfill(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261127000003_CreateSisWebsiteInquiryAssignments.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"'
        );
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_website_inquiry_assignments');
            \CreateSisWebsiteInquiryAssignments::define($table);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', $sql);
            self::assertStringContainsString('swia_assignee_page', $sql);
            self::assertStringContainsString('CASCADE', $sql);
            self::assertDoesNotMatchRegularExpression(
                '/INSERT INTO|UPDATE |DELETE FROM|AUTO_INCREMENT|COLLATE/i',
                $sql
            );
            self::assertStringNotContainsString('sis_history_events', $sql);
            self::assertStringNotContainsString('sis_access_user_permissions', $sql);
        }
    }

    public function testRepeatPreservesPopulatedAssignmentsIntakeReceiptsPermissionsAndHistory(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
        $f = new WebsiteFormFixture();
        $s = $f->sis;
        $person = $s->legacyPerson($s->access->actor, 'Fictional', 'Operator')->id;
        $f->submit();
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        $f->inbox->assign($id, 0, 0, $s->access->actor, $person);
        $tables = ['sis_website_inquiry_assignments', 'sis_website_form_inbox', 'sis_website_form_receipts',
            'sis_website_form_access', 'sis_access_user_permissions', 'sis_history_events'];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \CreateSisWebsiteInquiryAssignments('20261127000003');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($s->connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
