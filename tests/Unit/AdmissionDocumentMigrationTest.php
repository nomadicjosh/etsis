<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdmissionDocumentMigrationTest extends TestCase
{
    public function testRetentionDefinitionsAndClassificationRecordsCompileWithoutChangingEarlierFiles(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261122000002_CreateSisAdmissionRetention.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            foreach (['policies', 'classifications'] as $kind) {
                $table = new CreateTable('sis_retention_fixture');
                \CreateSisAdmissionRetention::$kind($table);
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString('protected_metadata', $sql);
                self::assertStringContainsString($kind === 'policies' ? 'sarp_revision' : 'sadc_document_fk', $sql);
                self::assertDoesNotMatchRegularExpression(
                    '/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i',
                    $sql,
                );
                self::assertStringNotContainsString('sis_history_events', $sql);
            }
        }
    }

    public function testScopedHoldSchemaCompilesAcrossFiveDialectsWithoutBackfillingEarlierFacts(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261122000001_CreateSisAdmissionDocumentHolds.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_admission_document_holds');
            \CreateSisAdmissionDocumentHolds::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (
                ['protected_metadata', 'scope_sequence', 'sadh_scope', 'sadh_history', 'sadh_release',
                'sadh_application_fk', 'sadh_person_fk'] as $field
            ) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
            // Nullable release identifiers need an ordinary index: SQL Server allows only one NULL in a unique index.
            self::assertStringNotContainsString('UNIQUE', strtoupper($sql));
        }
    }

    public function testProtectedDocumentSchemaCompilesAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261117000004_CreateSisAdmissionDocuments.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_admission_documents');
            \CreateSisAdmissionDocuments::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (
                ['sis_admission_documents', 'protected_metadata', 'sad_application_fk', 'sad_person_fk',
                'sad_person_quota', 'sad_scan_work'] as $field
            ) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
