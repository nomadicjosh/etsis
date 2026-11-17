<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdmissionDocumentMigrationTest extends TestCase
{
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
