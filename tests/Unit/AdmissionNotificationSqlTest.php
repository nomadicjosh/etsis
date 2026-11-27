<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository;
use Infrastructure\Persistence\SqlRecords;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;

final class AdmissionNotificationSqlTest extends TestCase
{
    public static function drivers(): array
    {
        return array_map(static fn(string $driver): array => [$driver], ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci']);
    }

    #[DataProvider('drivers')]
    public function testOwnerReadsAreBoundedAndDeletionPinsTheExactAccountAndRevision(string $driver): void
    {
        $captured = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (
                array $values
            ) use (
                &$captured,
                $sql
            ): bool {
                $captured[] = [$sql, $values];
                return true;
            });
            $statement->method('fetchAll')->willReturn([]);
            $statement->method('fetch')->willReturn(false);
            $statement->method('rowCount')->willReturn(1);
            return $statement;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $field): string => '"' . $field . '"'
        );
        $database = $this->createStub(Database::class);
        $database->method('getConnection')->willReturn($connection);
        $repository = new SqlAdmissionUpdatesRepository(new SqlRecords($database));
        $owner = EntityId::generate();
        $notice = EntityId::generate();
        $at = '2026-11-26T10:00:00.000000Z';
        self::assertSame([], $repository->page($owner, true, $at, $notice));
        self::assertNull($repository->owned($owner, $notice));
        self::assertTrue($repository->delete($owner, $notice, 3));
        self::assertCount(3, $captured);
        [$page, $parameters] = $captured[0];
        self::assertSame([$owner->value, $owner->value, $at, $at, $notice->value], $parameters);
        self::assertStringContainsString(match ($driver) {
            'sqlsrv' => 'TOP (21)', 'oci' => 'ROWNUM <= 21', default => 'LIMIT 21',
        }, $page);
        self::assertStringContainsString('n."read_revision" < n."revision"', $page);
        foreach (array_slice($captured, 0, 2) as [$query]) {
            self::assertStringContainsString('n."owner_user_id" = ? AND p."owner_user_id" = ?', $query);
            self::assertStringContainsString('a."person_id"', $query);
            self::assertStringNotContainsString('sis_admission_cases', $query);
            self::assertStringNotContainsString($owner->value, $query);
        }
        self::assertSame([$owner->value, $owner->value, $notice->value], $captured[1][1]);
        self::assertSame(
            'DELETE FROM "sis_admission_updates" WHERE "id" = ? AND "owner_user_id" = ? AND "revision" = ?',
            $captured[2][0]
        );
        self::assertSame([$notice->value, $owner->value, 3], $captured[2][1]);
    }
}
