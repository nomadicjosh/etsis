<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\Repository\ReportCaptureSource;
use Domain\Reporting\ValueObject\ReportCapture;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\ValidationException;
use JsonException;
use PDO;
use Qubus\Expressive\Database;
use Throwable;

/** Independent bounded read transaction; publication/storage runs only after this scope has ended. */
final readonly class SqlReportCaptureSource implements ReportCaptureSource
{
    public function __construct(private Database $db, private SqlRelationalReportSource $source)
    {
    }
    /**
     * @throws Throwable
     * @throws JsonException
     */
    public function capture(#[\SensitiveParameter] ReportQuery $query): ReportCapture
    {
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
            $rows = [];
            $bytes = 0;
            for ($offset = 0;; $offset += 10) {
                if ((hrtime(true) - $started) / 1e9 > 15) {
                    throw new ValidationException([['field' => 'report', 'message' =>
                        'This static capture reached its work limit. '
                        . 'Narrow the report; no partial capture was saved.']]);
                }
                $page = $this->source->boundedPage($query, $offset, 11);
                if (
                    $offset + min(10, count($page)) > ReportCapture::MAX_ROWS ||
                    ($offset + 10 >= ReportCapture::MAX_ROWS && count($page) > 10)
                ) {
                    ReportCapture::tooLarge();
                }
                foreach (array_slice($page, 0, 10) as $row) {
                    $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
                    if ($bytes > ReportCapture::MAX_BYTES) {
                        ReportCapture::tooLarge();
                    }
                    $rows[] = $row;
                }
                if (count($page) <= 10) {
                    break;
                }
            }
            $capture = new ReportCapture(array_column($query->definition['columns'], 'label'), $rows);
            $pdo->commit();
            return $capture;
        } catch (\Throwable $error) {
            $this->rollBack($pdo);
            throw $error;
        } finally {
            if ($restore !== null) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $restore);
            }
        }
    }
    private function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
