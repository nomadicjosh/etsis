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

    public function testNonzeroResultWindowsCompileForAllFiveDialects(): void
    {
        $select = 'SELECT name FROM roles ORDER BY name';
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            self::assertSame($select . ' LIMIT 51 OFFSET 50', SqlSelectLimit::page($select, $driver, 51, 50));
        }
        self::assertSame($select . ' OFFSET 50 ROWS FETCH NEXT 51 ROWS ONLY',
            SqlSelectLimit::page($select, 'sqlsrv', 51, 50));
        $oracle = SqlSelectLimit::page($select, 'oci', 51, 50);
        self::assertStringContainsString('ROWNUM <= 101', $oracle);
        self::assertStringContainsString('"report_page_row" > 50', $oracle);
        $this->expectException(InvalidArgumentException::class);
        SqlSelectLimit::page($select, 'sqlite', 51, -1);
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
