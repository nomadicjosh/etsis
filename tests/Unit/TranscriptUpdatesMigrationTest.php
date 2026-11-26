<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class TranscriptUpdatesMigrationTest extends TestCase
{
    public function testDisposableTranscriptReferencesCompileOnAllFiveDialectsWithoutBackfill(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261125000017_CreateSisTranscriptUpdates.php";
        $table = new CreateTable("sis_transcript_updates");
        \CreateSisTranscriptUpdates::define($table);
        $connection = $this->createStub(Connection::class);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("CREATE TABLE", $sql);
            self::assertStringContainsString("CASCADE", $sql);
            self::assertStringContainsString("stnu_request_unique", $sql);
            self::assertStringContainsString("stnu_owner_page", $sql);
            self::assertStringContainsString("stnu_owner_all", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/INSERT INTO|UPDATE |DELETE FROM|AUTO_INCREMENT|COLLATE|information_schema/i",
                $sql,
            );
            foreach (["sis_history_events", "encrypted_recipient", "evidence", "pdf", "payload"] as $private) {
                self::assertStringNotContainsString($private, $sql);
            }
        }
    }
}
