<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class EnrollmentUpdatesMigrationTest extends TestCase
{
    public function testDisposableEnrollmentReferencesCompileAcrossFiveDialectsWithoutBackfillOrPrivatePayloads(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261127000000_CreateSisEnrollmentUpdates.php';
        $table = new CreateTable('sis_enrollment_updates');
        \CreateSisEnrollmentUpdates::define($table);
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $id): string => '"' . $id . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('CREATE TABLE', $sql);
            $indexes = ['senu_enrollment_unique', 'senu_owner_page', 'senu_owner_all', 'sis_registration_streams'];
            foreach ($indexes as $required) {
                self::assertStringContainsString($required, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                '/INSERT INTO|UPDATE |DELETE FROM|AUTO_INCREMENT|COLLATE|information_schema/i',
                $sql
            );
            foreach (['sis_history_events', 'payload', 'reason', 'policy_reference', 'standing_title'] as $private) {
                self::assertStringNotContainsString($private, $sql);
            }
        }
    }
}
