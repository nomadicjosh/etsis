<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class NotificationStorageMigrationTest extends TestCase
{
    public function testDisposableNoticesAndPersonPreferencesCompileOnAllFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261125000015_CreateSisNotificationPreferences.php";
        require_once dirname(__DIR__, 2) . "/database/migrations/20261125000016_CreateSisAcademicUpdates.php";
        $tables = [new CreateTable("sis_notification_preferences"), new CreateTable("sis_academic_updates")];
        \CreateSisNotificationPreferences::define($tables[0]);
        \CreateSisAcademicUpdates::define($tables[1]);
        $connection = $this->createStub(Connection::class);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            foreach ($tables as $index => $table) {
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString("CREATE TABLE", $sql);
                self::assertDoesNotMatchRegularExpression(
                    "/INSERT INTO|UPDATE |DELETE FROM|AUTO_INCREMENT|COLLATE|information_schema/i",
                    $sql,
                );
                self::assertStringNotContainsString("sis_history_events", $sql);
                if ($index === 1) {
                    self::assertStringContainsString("CASCADE", $sql);
                    self::assertStringContainsString("sagn_credit_unique", $sql);
                }
            }
        }
    }
}
