<?php

declare(strict_types=1);

namespace Tests\Unit;

use Infrastructure\Persistence\PostgresSchemaCompiler;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\AlterTable;

final class PostgresSchemaCompilerTest extends TestCase
{
    public function testChangingAColumnTypePreservesUnspecifiedNullabilityAndDefault(): void
    {
        $table = new AlterTable('fictional_records');
        $table->toString('reference', 40);
        $sql = new PostgresSchemaCompiler($this->createStub(Connection::class))->alter($table);
        self::assertCount(1, $sql);
        self::assertSame('ALTER TABLE "fictional_records" ALTER COLUMN "reference" TYPE VARCHAR(40)', $sql[0]['sql']);
        self::assertSame([], $sql[0]['params']);
    }

    public function testExplicitNullabilityUsesIndependentPostgresActions(): void
    {
        foreach ([true => 'DROP', false => 'SET'] as $nullable => $action) {
            $table = new AlterTable('fictional_records');
            $table->toString('reference', 26)->set('nullable', (bool) $nullable);
            $sql = new PostgresSchemaCompiler($this->createStub(Connection::class))->alter($table);
            self::assertSame('ALTER TABLE "fictional_records" ALTER COLUMN "reference" TYPE VARCHAR(26), '
                . 'ALTER COLUMN "reference" ' . $action . ' NOT NULL', $sql[0]['sql']);
            self::assertDoesNotMatchRegularExpression('/MODIFY|DROP DEFAULT|DROP COLUMN/i', $sql[0]['sql']);
        }
    }
}
