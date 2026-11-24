<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Service\DatabaseService;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;

final class DatabaseServiceTest extends TestCase
{
    private function builder(string $driver): DatabaseService
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('supportsReturning')->willReturn($driver === 'pgsql');
        return DatabaseService::fromInstance($connection, primaryKeyName: 'record_id', tablePrefix: 'fictional_');
    }

    public function testPostgresOrdinaryInsertHasAValidConstantReturningClauseWithoutPrivateColumns(): void
    {
        $builder = $this->builder('pgsql');
        $query = $builder->table('records')->debugSqlQuery()->insert([
            'record_id' => 'FICTIONAL_ID', 'private_value' => 'FICTIONAL_PRIVATE_VALUE',
        ]);
        self::assertInstanceOf(DatabaseService::class, $query);
        self::assertSame(
            'INSERT INTO fictional_records (record_id,private_value) VALUES (?, ?) RETURNING 1',
            $query->getSqlQuery()
        );
        self::assertSame(['FICTIONAL_ID', 'FICTIONAL_PRIVATE_VALUE'], $query->getSqlParameters());
        self::assertSame('record_id', $query->getPrimaryKeyname());
    }

    public function testExplicitPostgresReturningSelectionIsPreserved(): void
    {
        $query = $this->builder('pgsql')->table('records')->returning('record_id')->debugSqlQuery()
            ->insert(['record_id' => 'FICTIONAL_ID']);
        self::assertStringEndsWith(' RETURNING record_id', $query->getSqlQuery());
    }

    public function testOtherOrdinaryInsertsKeepTheirExistingSql(): void
    {
        foreach (['sqlite', 'mysql'] as $driver) {
            $query = $this->builder($driver)->table('records')->debugSqlQuery()
                ->insert(['record_id' => 'FICTIONAL_ID']);
            self::assertSame('INSERT INTO fictional_records (record_id) VALUES (?)', $query->getSqlQuery());
        }
    }
}
