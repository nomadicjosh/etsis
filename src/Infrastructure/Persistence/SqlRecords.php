<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Shared\Exception\RecordNotFoundException;
use InvalidArgumentException;
use PDO;
use Qubus\Expressive\Database;

/** Prepared persistence operations. Identifiers are repository-owned, never request input. */
final readonly class SqlRecords
{
    public function __construct(private Database $db)
    {
    }

    public function quote(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid persistence identifier.');
        }

        return $this->db->getConnection()->quoteIdentifier($identifier);
    }

    /**
 * @param array<string, scalar|null> $criteria
 * @return array<string, mixed>
 */
    public function one(string $table, array $criteria): array
    {
        $rows = $this->rows($table, $criteria);

        return $rows[0] ?? throw new RecordNotFoundException('Record not found.');
    }

    /**
 * @param array<string, scalar|null> $criteria
 * @return list<array<string, mixed>>
 */
    public function rows(string $table, array $criteria = [], ?int $limit = null): array
    {
        return $this->fetch(
            'SELECT * FROM ' . $this->quote($table) . $this->where($criteria),
            array_values($criteria),
            $limit
        );
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, array $data): void
    {
        $this->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            implode(', ', array_map($this->quote(...), array_keys($data))),
            implode(', ', array_fill(0, count($data), '?')),
        ), array_values($data));
    }

    /**
 * @param array<string, scalar|null> $data
 * @param array<string, scalar|null> $criteria
 */
    public function update(string $table, array $data, array $criteria): int
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('A scoped update is required.');
        }
        $assignments = array_map(fn (string $key): string => $this->quote($key) . ' = ?', array_keys($data));

        return $this->execute('UPDATE ' . $this->quote($table) . ' SET ' . implode(', ', $assignments)
            . $this->where($criteria), [...array_values($data), ...array_values($criteria)]);
    }

    /** Acquire a current row lock within the caller's transaction, without a premature snapshot read. */
    public function lock(string $table, string $id, string $column): void
    {
        $field = $this->quote($column);
        $driver = (string) $this->db->getConnection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quoted = $this->quote($table);
        $key = $this->quote('id');
        if ($driver === 'sqlite') {
            // SQLite needs a write before the read; the other engines supply row locking reads.
            $this->execute("UPDATE $quoted SET $field = $field WHERE $key = ?", [$id]);
        }
        if ($this->fetch(SqlRowLock::select($quoted, $key, $driver), [$id]) === []) {
            throw new RecordNotFoundException('Record not found.');
        }
    }

    /**
 * @param list<scalar|null> $parameters
 * @return list<array<string, mixed>>
 */
    public function fetch(string $statement, array $parameters = [], ?int $limit = null): array
    {
        if ($limit !== null) {
            $driver = $this->db->getConnection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $statement = SqlSelectLimit::apply($statement, (string) $driver, $limit);
        }
        $query = $this->db->getConnection()->pdo->prepare($statement);
        $query->execute($parameters);

        if ($limit === null) {
            return array_map($this->normalize(...), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
        }
        $rows = [];
        try {
            while (count($rows) < $limit && ($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $this->normalize($row);
            }
        } finally {
            $query->closeCursor();
        }

        return $rows;
    }

    /** @param list<scalar|null> $parameters */
    public function execute(string $statement, array $parameters = []): int
    {
        $query = $this->db->getConnection()->pdo->prepare($statement);
        $query->execute($parameters);

        return $query->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $column => $value) {
            // PDO Oracle may return CLOB columns as streams instead of strings.
            if (is_resource($value)) {
                $content = stream_get_contents($value);
                if ($content === false) {
                    throw new \RuntimeException('Unable to read a stored text column.');
                }
                $row[$column] = $content;
            }
        }

        return $row;
    }

    /** @param array<string, scalar|null> $criteria */
    private function where(array $criteria): string
    {
        if ($criteria === []) {
            return '';
        }
        foreach ($criteria as $value) {
            if ($value === null) {
                throw new InvalidArgumentException('NULL predicates require an explicit repository query.');
            }
        }

        return ' WHERE ' . implode(' AND ', array_map(
            fn (string $key): string => $this->quote($key) . ' = ?',
            array_keys($criteria),
        ));
    }
}
