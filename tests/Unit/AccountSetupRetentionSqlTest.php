<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccountSetupRepository;
use Infrastructure\Persistence\SqlRecords;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;

final class AccountSetupRetentionSqlTest extends TestCase
{
    public static function drivers(): array
    {
        return array_map(static fn(string $driver): array => [$driver], ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci']);
    }

    #[DataProvider('drivers')]
    public function testCandidatesMetadataLocksAndConditionalClearAreBoundedAndPortable(string $driver): void
    {
        $id = EntityId::generate();
        $cutoff = '2026-11-25T10:00:00.000000Z';
        $captured = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured, $id): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (
                array $parameters
            ) use (
                &$captured,
                $sql
): bool {
                $captured[] = [$sql, $parameters];
                return true;
            });
            $statement->method('fetch')->willReturnOnConsecutiveCalls(['id' => $id->value, 'has_notice' => '1'], false);
            $statement->method('fetchAll')->willReturn([['id' => $id->value]]);
            $statement->method('rowCount')->willReturn(1);
            return $statement;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $name): string => '"' . $name . '"'
        );
        $database = $this->createStub(Database::class);
        $database->method('getConnection')->willReturn($connection);
        $repository = new SqlAccountSetupRepository(new SqlRecords($database));
        self::assertSame([$id->value], array_map(
            static fn(EntityId $value): string => $value->value,
            $repository->retentionCandidates($cutoff, $id, 3)
        ));
        [$query, $parameters] = $captured[0];
        self::assertSame(['queued', 'sending', $cutoff, $id->value], $parameters);
        self::assertStringContainsString('"encrypted_notice" IS NOT NULL', $query);
        self::assertStringContainsString('"expires_at" <= ?', $query);
        self::assertStringContainsString('"id" > ? ORDER BY "id"', $query);
        self::assertStringContainsString(match ($driver) {
            'sqlsrv' => 'TOP (3)', 'oci' => 'ROWNUM <= 3', default => 'LIMIT 3',
        }, $query);
        self::assertStringNotContainsString($id->value, $query);

        $metadata = $repository->retentionRecord($id, lock: true);
        self::assertSame(1, $metadata['has_notice']);
        [$query, $parameters] = $captured[array_key_last($captured)];
        self::assertSame([$id->value], $parameters);
        self::assertStringContainsString(
            (in_array($driver, ['sqlite', 'oci'], true) ? 'SUBSTR' : 'SUBSTRING') . '("sealed_grant", 1, 8193)',
            $query
        );
        self::assertStringContainsString('CASE WHEN "encrypted_notice" IS NULL THEN 0 ELSE 1 END', $query);
        foreach ($captured as [$sql]) {
            self::assertDoesNotMatchRegularExpression('/(?:SELECT|,)\s*"encrypted_notice"/i', $sql);
        }

        self::assertTrue($repository->redactNotice($id, 'fictional hash', 'queued', $cutoff));
        [$query, $parameters] = $captured[array_key_last($captured)];
        self::assertSame(['expired', $id->value, 'fictional hash', 'queued', $cutoff], $parameters);
        self::assertStringContainsString('"encrypted_notice" = NULL', $query);
        self::assertStringContainsString('"consumed_at" IS NULL', $query);
        self::assertStringNotContainsString('fictional hash', $query);
        self::assertTrue($repository->redactNotice($id, 'fictional hash', 'sending', $cutoff));
        self::assertSame('uncertain', $captured[array_key_last($captured)][1][0]);
    }
}
