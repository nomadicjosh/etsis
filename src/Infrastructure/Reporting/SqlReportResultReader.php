<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\ValidationException;
use PDO;
use Qubus\Expressive\Database;

/** Independent bounded read transaction; publication/storage runs only after this scope has ended. */
final readonly class SqlReportResultReader
{
    public function __construct(private Database $db, private SqlRelationalReportSource $source)
    {
    }
    /** Consistent complete traversal; callbacks must not write through this connection.
     * @param callable(list<array<string,mixed>>):void $accept
     */
    public function read(
        #[\SensitiveParameter] ReportQuery $query,
        callable $accept,
        int $maximumRows,
        int $maximumBytes,
        int $seconds,
        int $pageSize = 50,
    ): void {
        if (
            $maximumRows < 1 || $maximumRows > 25000 || $maximumBytes < 1 ||
            $maximumBytes > 16 * 1024 * 1024 || $seconds < 1 || $seconds > 30 || $pageSize < 1 || $pageSize > 50
        ) {
            throw new \InvalidArgumentException('Use bounded complete-report traversal limits.');
        }
        $pdo = $this->db->getConnection()->pdo;
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('A report capture requires its own consistent read transaction.');
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $restore = null;
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        } elseif ($driver === 'sqlsrv') {
            $statement = $pdo->query(
                'SELECT transaction_isolation_level FROM sys.dm_exec_sessions WHERE session_id = @@SPID'
            );
            if ($statement === false) {
                throw new \RuntimeException('Read isolation is unavailable.');
            }
            $previous = $statement->fetchColumn();
            $restore = [1 => 'READ UNCOMMITTED', 2 => 'READ COMMITTED', 3 => 'REPEATABLE READ',
                4 => 'SERIALIZABLE', 5 => 'SNAPSHOT'][(int) $previous] ??
            throw new \RuntimeException('Unknown read isolation.');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        } elseif (!in_array($driver, ['pgsql', 'sqlite', 'oci'], true)) {
            throw new \RuntimeException('This driver does not support consistent report captures.');
        }
        try {
            $pdo->beginTransaction();
            if ($driver === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            } elseif ($driver === 'oci') {
                $pdo->exec('SET TRANSACTION READ ONLY');
            }
            $started = hrtime(true);
            $this->source->assertReady($query);
            $bytes = 0;
            for ($offset = 0;; $offset += $pageSize) {
                if ((hrtime(true) - $started) / 1e9 > $seconds) {
                    throw new ValidationException([['field' => 'report', 'message' =>
                        'This complete report reached its work limit. '
                        . 'Narrow the report; no partial result was saved.']]);
                }
                $page = $this->source->boundedPage($query, $offset, $pageSize + 1, checkSource: false);
                if ($offset + count($page) > $maximumRows) {
                    self::tooLarge();
                }
                foreach (array_slice($page, 0, $pageSize) as $row) {
                    $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
                    if ($bytes > $maximumBytes) {
                        self::tooLarge();
                    }
                }
                $accept(array_slice($page, 0, $pageSize));
                if ((hrtime(true) - $started) / 1e9 > $seconds) {
                    throw new ValidationException([['field' => 'report', 'message' =>
                        'This complete report reached its work limit. No partial result was saved.']]);
                }
                if (count($page) <= $pageSize) {
                    break;
                }
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $this->rollBack($pdo);
            throw $error;
        } finally {
            if ($restore !== null) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $restore);
            }
        }
    }
    private static function tooLarge(): never
    {
        throw new ValidationException([['field' => 'report', 'message' =>
            'This complete report exceeds its row or size limit. Narrow its filters; no partial result was saved.']]);
    }
    private function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
