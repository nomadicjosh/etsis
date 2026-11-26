<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;

final class TranscriptWorklistMigrationTest extends TestCase
{
    public function testCurrentWorkAssignmentsCompileOnAllFiveDialectsWithoutBusinessBackfill(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261125000018_CreateSisTranscriptAssignments.php";
        $table = new CreateTable("sis_transcript_assignments");
        \CreateSisTranscriptAssignments::define($table);
        $connection = $this->createStub(Connection::class);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $queue = new AlterTable("sis_transcript_requests");
            \CreateSisTranscriptAssignments::queue($queue);
            $sql = json_encode(
                [new $compiler($connection)->create($table), new $compiler($connection)->alter($queue)],
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("CREATE TABLE", $sql);
            self::assertStringContainsString("sta_assignee_page", $sql);
            self::assertStringContainsString("str_status_page", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/INSERT INTO|UPDATE |DELETE FROM|AUTO_INCREMENT|COLLATE|information_schema/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
            self::assertStringNotContainsString("sis_access_user_permissions", $sql);
        }
    }
}
