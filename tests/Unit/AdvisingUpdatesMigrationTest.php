<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdvisingUpdatesMigrationTest extends TestCase
{
    public function testPrivateNoticeReferencesCompileAcrossFiveDialectsWithoutRewritingHistory(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261124000007_CreateSisAdvisingUpdates.php";
        foreach (
            ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect
        ) {
            $table = new CreateTable("sis_advising_updates");
            \CreateSisAdvisingUpdates::define($table);
            $connection = $this->createStub(Connection::class);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $name): string => '"' . $name . '"',
                );
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $compiler($connection)->create($table),
                JSON_THROW_ON_ERROR,
            );
            foreach (
                [
                    "owner_user_id",
                    "person_id",
                    "pursuit_id",
                    "checkin_id",
                    "sadu_owner_page",
                    "sadu_checkin_unique",
                ] as $field
            ) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }

    public function testPostgresReferencesInheritQuotedParentCollationsWithoutChangingOrdinaryFields(): void
    {
        require_once dirname(__DIR__, 2)
        . '/database/migrations/20261124000007_CreateSisAdvisingUpdates.php';
        $table = new CreateTable('sis_advising_updates');
        \CreateSisAdvisingUpdates::define($table);
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $name): string => '"' . $name . '"'
        );
        $columns = ['person_id', 'student_id', 'pursuit_id', 'checkin_id', 'owner_user_id'];
        $compiler = new \Infrastructure\Persistence\PostgresReferencedColumnCompiler(
            $connection,
            array_fill_keys($columns, '"public"."etsis_unicode_ci"'),
        );
        $sql = json_encode($compiler->create($table), JSON_THROW_ON_ERROR);
        self::assertSame(5, substr_count($sql, 'COLLATE'));
        foreach ($columns as $column) {
            self::assertStringContainsString($column, $sql);
        }
        self::assertStringContainsString('sadu_person_fk', $sql);
        self::assertStringContainsString('sadu_checkin_unique', $sql);
        self::assertStringNotContainsString('DROP', $sql);
    }
}
