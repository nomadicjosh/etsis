<?php

declare(strict_types=1);

use Infrastructure\Persistence\SqlSelectLimit;
use PHPUnit\Framework\TestCase;

final class SqlSelectLimitTest extends TestCase
{
    public function testBoundedReadsCompileForAllFiveDatabaseDialects(): void
    {
        $select = 'SELECT name FROM roles WHERE id > ? ORDER BY id';
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            self::assertSame($select . ' LIMIT 25', SqlSelectLimit::apply($select, $driver, 25));
        }
        self::assertSame('SELECT TOP (25) name FROM roles WHERE id > ? ORDER BY id',
            SqlSelectLimit::apply($select, 'sqlsrv', 25));
        self::assertSame('SELECT DISTINCT TOP (25) name FROM roles ORDER BY name',
            SqlSelectLimit::apply('SELECT DISTINCT name FROM roles ORDER BY name', 'sqlsrv', 25));
        self::assertSame('SELECT * FROM (' . $select . ') WHERE ROWNUM <= 25',
            SqlSelectLimit::apply($select, 'oci', 25));
    }

    public function testUnboundedOrNonSelectRequestsAreRejected(): void
    {
        foreach ([['SELECT name FROM roles', 0], ['SELECT name FROM roles', 1001], ['DELETE FROM roles', 1]] as [$sql, $limit]) {
            try {
                SqlSelectLimit::apply($sql, 'sqlite', $limit);
                self::fail('Invalid bounded query accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
