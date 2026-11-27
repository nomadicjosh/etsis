<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptArtifactReader;
use Infrastructure\Persistence\Repository\SqlTranscriptArtifactRepository;
use Infrastructure\Persistence\SqlRecords;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;

final class TranscriptArtifactSqlTest extends TestCase
{
    public static function drivers(): array
    {
        return array_map(static fn(string $driver): array => [$driver], ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci']);
    }

    #[DataProvider('drivers')]
    public function testSelectedReadsAreBoundedPortablePrivateAndAcquireNoLocks(string $driver): void
    {
        $queries = [];
        $id = EntityId::generate();
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$queries, $id): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (
                array $values
            ) use (
                &$queries,
                $sql
): bool {
                $queries[] = [$sql, $values];
                return true;
            });
            $statement->method('fetch')->willReturnOnConsecutiveCalls(['id' => $id->value], false);
            return $statement;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $key): string => '"' . $key . '"');
        $database = $this->createStub(Database::class);
        $database->method('getConnection')->willReturn($connection);
        $repository = new SqlTranscriptArtifactRepository(new SqlRecords($database));
        $repository->document($id);
        $repository->request($id);
        $repository->delivery($id);
        self::assertCount(3, $queries);
        foreach ($queries as [$sql, $values]) {
            self::assertSame([$id->value], $values);
            self::assertStringStartsWith('SELECT ', $sql);
            self::assertStringNotContainsString($id->value, $sql);
            self::assertStringNotContainsString('encrypted_recipient', $sql);
            self::assertStringNotContainsString('FOR UPDATE', $sql);
            self::assertStringContainsString(match ($driver) {
                'sqlsrv' => 'TOP (1)', 'oci' => 'ROWNUM <= 1', default => 'LIMIT 1',
            }, $sql);
        }
        $substring = in_array($driver, ['sqlite', 'oci'], true) ? 'SUBSTR' : 'SUBSTRING';
        foreach ([$queries[0][0], $queries[2][0]] as $sql) {
            self::assertStringContainsString($substring . '("encrypted_content", 1, ' .
                (TranscriptArtifactReader::MAX_CIPHERTEXT + 1) . ')', $sql);
        }
        self::assertStringNotContainsString('encrypted_content', $queries[1][0]);
    }
}
