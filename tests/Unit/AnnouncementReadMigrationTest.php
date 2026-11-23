<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AnnouncementReadMigrationTest extends TestCase
{
    public function testReadMetadataCompilesAcrossFiveDialectsWithoutChangingContentOrHistory(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000002_CreateCampusAnnouncementReads.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_announcement_reads');
            \CreateCampusAnnouncementReads::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn (string $name): string => '"' . $name . '"',
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (['owner_user_id', 'post_id', 'revision', 'read_at', 'sar_owner_post'] as $field) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
