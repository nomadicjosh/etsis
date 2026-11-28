<?php

declare(strict_types=1);

namespace Tests\Support;

use Dotenv\Dotenv;
use LogicException;
use PDO;
use Qubus\Expressive\Connection\DriverConnection;
use Qubus\Expressive\Connection\PdoConnection;
use RuntimeException;
use Throwable;

/** Opt-in disposable database. The configured application schema is never selected or mutated. */
final class NativeQualificationDatabase
{
    private ?PdoConnection $server = null;
    private ?PdoConnection $database = null;
    private bool $created = false;
    private bool $marked = false;
    private readonly string $name;
    private readonly string $owner;
    private array $settings;
    private string $sourceSchema;
    private string $lease;
    private static ?\WeakMap $active = null;
    private string $phase = 'created';
    private bool $schemaReady = false;
    private static ?self $shared = null;
    private ?PdoConnection $source = null;
    private string $driver;
    private array $seedOrder = [];

    public static function acquire(string $directory): self
    {
        return self::$shared ??= new self($directory);
    }

    public function __construct(?string $leaseDirectory = null)
    {
        if (getenv('ETSIS_WORKFLOW_NATIVE_QUALIFICATION') !== '1') {
            throw new LogicException('Native workflow qualification requires explicit opt-in.');
        }
        if ($leaseDirectory === null || !is_dir($leaseDirectory) || (fileperms($leaseDirectory) & 0077) !== 0) {
            throw new LogicException('Native qualification requires its private fixture directory.');
        }
        $this->lease = $leaseDirectory . '/native-database-lease.json';
        $this->name = 'etsis_qualify_' . bin2hex(random_bytes(16));
        $this->owner = bin2hex(random_bytes(16));
        if (self::$active === null) {
            self::$active = new \WeakMap();
            register_shutdown_function(static function (): void {
                foreach (self::$active as $fixture => $_) {
                    try {
                        $fixture->close();
                    } catch (Throwable) {
                        fwrite(STDERR, "Native fixture cleanup incomplete; retain its private ownership lease.\n");
                    }
                }
            });
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, static function (): never {
                    exit(124);
                });
            }
        }
        self::$active[$this] = true;
        try {
            $environment = Dotenv::createArrayBacked(dirname(__DIR__, 2))->load();
            $this->driver = $environment['DB_CONNECTION'] ?? '';
            if (!in_array($this->driver, ['mysql', 'pgsql'], true)) {
                throw new LogicException('Select a configured MySQL-family or PostgreSQL database.');
            }
            $original = ['dsn' => $environment['DB_DSN'] ?? '', 'username' => $environment['DB_USER'] ?? '',
                'password' => $environment['DB_PASSWORD'] ?? ''];
            if (!preg_match('/(?:^(?:mysql|pgsql):|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/D', $original['dsn'], $source)) {
                throw new LogicException('Use an explicit configured source-schema name.');
            }
            $this->sourceSchema = $source[1];
            $endpoint = getenv('ETSIS_QUALIFICATION_ENDPOINT');
            $this->settings = self::settingsFor($original, $this->name, $endpoint === false ? null : $endpoint);
            if ($this->settings['driver'] !== 'pdo_' . $this->driver) {
                throw new LogicException('The configured driver and DSN family must agree.');
            }
            $server = $this->settings;
            $this->phase = 'creating';
            if (!$this->writeLease() || !chmod($this->lease, 0600)) {
                throw new RuntimeException('Preserve the private qualification ownership lease before creation.');
            }
            if ($this->driver === 'pgsql') {
                // The application owner keeps no CREATEDB grant. Control credentials never reach workers.
                $file = getenv('ETSIS_QUALIFICATION_CONTROL_FILE');
                if (!is_string($file) || !is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0) {
                    throw new LogicException('Use a private PostgreSQL database-creation credential file.');
                }
                $control = json_decode(file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
                if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $this->settings['username'])) {
                    throw new LogicException('Review the configured PostgreSQL owner name.');
                }
                $server['dsn'] = preg_replace('/;dbname=[^;]+$/D', ';dbname=postgres', $server['dsn']);
                $server['username'] = $control['username'];
                $server['password'] = $control['password'];
                $this->server = self::connect($server);
                $this->server->pdo->exec('CREATE DATABASE "' . $this->name . '" OWNER "' .
                    $this->settings['username'] . '" TEMPLATE template0');
            } else {
                $server['dsn'] = preg_replace('/;dbname=[^;]+/', '', $server['dsn']);
                $this->server = self::connect($server);
                $this->server->pdo->exec('CREATE DATABASE `' . $this->name .
                    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            $this->created = true;
            $this->database = self::connect($this->settings);
            if (self::selectedDatabase($this->database) !== $this->name) {
                throw new LogicException('Native qualification isolation failed.');
            }
            $this->database->pdo->exec('CREATE TABLE etsis_qualification_owner (id CHAR(32) PRIMARY KEY)');
            $statement = $this->database->pdo->prepare('INSERT INTO etsis_qualification_owner (id) VALUES (?)');
            $statement->execute([$this->owner]);
            $this->marked = true;
            if (!$this->writeLease() || !chmod($this->lease, 0600)) {
                throw new RuntimeException('Cannot preserve the private qualification ownership lease.');
            }
        } catch (Throwable $error) {
            $this->close();
            // Connection exceptions may contain host/user details. Never expose the original exception chain.
            throw new RuntimeException(
                'Cannot create the isolated native qualification database; check access privately.'
            );
        }
    }

    /** Pure target validation; credentials remain in memory and private worker stdin only. */
    public static function settingsFor(
        #[\SensitiveParameter] array $configuration,
        string $name,
        ?string $endpoint = null,
    ): array {
        if (!preg_match('/^etsis_qualify_[a-f0-9]{32}$/D', $name)) {
            throw new LogicException('Use a generated disposable qualification database.');
        }
        $dsn = $configuration['dsn'] ?? null;
        if (
            !is_string($dsn) || !preg_match('/^(mysql|pgsql):/', $dsn, $family) ||
            preg_match('/[\x00-\x1F\x7F]/', $dsn)
        ) {
            throw new LogicException('Use the configured MySQL-family or PostgreSQL DSN.');
        }
        $driver = $family[1];
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if (
                !in_array($key, $driver === 'mysql' ? ['host', 'port', 'dbname', 'charset', 'unix_socket'] :
                    ['host', 'port', 'dbname'], true) ||
                $value === null || $value === '' || isset($parts[$key])
            ) {
                throw new LogicException('Use an unambiguous MySQL-family DSN.');
            }
            $parts[$key] = $value;
        }
        if (
            !isset($parts['dbname']) || $parts['dbname'] === $name ||
            (!isset($parts['host']) && !isset($parts['unix_socket']))
        ) {
            throw new LogicException('Keep the qualification target separate from the configured database.');
        }
        if ($endpoint !== null) {
            if (
                !preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D', $endpoint, $match) ||
                (int) $match[1] < 1 || (int) $match[1] > 65535
            ) {
                throw new LogicException('Use an explicit loopback port for the mapped development endpoint.');
            }
            unset($parts['unix_socket']);
            $parts['host'] = '127.0.0.1';
            $parts['port'] = (string) (int) $match[1];
        }
        unset($parts['dbname']);
        $parts['dbname'] = $name;
        $configuration['driver'] = 'pdo_' . $driver;
        $configuration['dsn'] = $driver . ':' . implode(';', array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($parts),
            array_values($parts)
        ));
        $configuration['options'] = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 3];
        return $configuration;
    }

    public function connection(): PdoConnection
    {
        return $this->database ?? throw new LogicException('The qualification database is closed.');
    }

    /** Clone only installed DDL; seed exclusively from a new fictional SQLite fixture, never application rows. */
    public function seed(\SisFixture $prototype): void
    {
        $this->phase = $this->schemaReady ? 'resetting' : 'cloning';
        $this->writeLease();
        $tables = $prototype->connection->listTables();
        if (count($tables) > 400) {
            throw new LogicException('The native qualification schema exceeds its bounded table inventory.');
        }
        if ($this->driver === 'pgsql') {
            $this->seedPostgres($prototype, $tables);
            return;
        }
        $this->database->pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        if (!$this->schemaReady) {
            foreach ($tables as $index => $table) {
                if ($index % 20 === 0) {
                    $this->phase = 'cloning_' . $index;
                    $this->writeLease();
                }
                if ($table === 'users') {
                    $definition = $this->server->pdo->query('SHOW FULL COLUMNS FROM `' . $this->sourceSchema .
                    "`.users LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
                    $type = $definition['Type'] ?? '';
                    $collation = $definition['Collation'] ?? '';
                    if (
                        !preg_match('/^(?:char|varchar)\([0-9]{1,3}\)$/D', $type) ||
                        !preg_match('/^[a-zA-Z0-9_]+$/D', $collation)
                    ) {
                        throw new LogicException('Use the installed identity-key type for fictional users.');
                    }
                    $charset = explode('_', $collation)[0];
                    $this->database->pdo->exec('CREATE TABLE users (user_id ' . $type . ' CHARACTER SET ' . $charset .
                        ' COLLATE ' . $collation . ' PRIMARY KEY, email VARCHAR(191), role VARCHAR(80), '
                        . 'first_name VARCHAR(191), last_name VARCHAR(191), '
                        . 'sign_in_enabled INTEGER NOT NULL DEFAULT 1)');
                    continue;
                }
                if (!preg_match('/^sis_[a-z0-9_]+$/D', $table)) {
                    throw new LogicException('An unsupported fixture table cannot enter native qualification.');
                }
                $ddl = $this->server->pdo->query('SHOW CREATE TABLE `' . $this->sourceSchema . '`.`' . $table . '`')
                ->fetch(PDO::FETCH_NUM)[1];
                $ddl = str_replace(
                    'REFERENCES `' . $this->sourceSchema . '`.`',
                    'REFERENCES `' . $this->name . '`.`',
                    $ddl
                );
                $this->database->pdo->exec($ddl);
            }
            $this->schemaReady = true;
        }
        $native = new \Infrastructure\Persistence\SqlRecords($this->database->queryBuilder());
        $this->phase = 'seeding';
        $this->writeLease();
        $this->database->pdo->beginTransaction();
        $counts = [];
        $total = 0;
        try {
            foreach ($tables as $table) {
                $this->database->pdo->exec('DELETE FROM `' . $table . '`');
            }
            foreach ($tables as $table) {
                $rows = $prototype->sql->rows($table, limit: 1000);
                if (count($rows) >= 1000) {
                    throw new LogicException('The fictional native seed exceeds its row allowance.');
                }
                $counts[$table] = count($rows);
                $total += count($rows);
                if ($total > 2000) {
                    throw new LogicException('The complete fictional seed exceeds its row allowance.');
                }
                foreach ($rows as $row) {
                    $native->insert($table, $row);
                }
            }
            $this->database->pdo->commit();
            $this->phase = 'validating';
            $this->writeLease();
            $this->verifyForeignKeys($counts);
        } catch (Throwable $error) {
            if ($this->database->pdo->inTransaction()) {
                $this->database->pdo->rollBack();
            }
            throw $error;
        } finally {
            $this->database->pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        }
        \EventStoreFixture::shareConfig(
            $this->database->queryBuilder(),
            \EventStoreFixture::config($prototype->connection->queryBuilder())
        );
        $this->phase = 'ready';
        $this->writeLease();
    }

    private static function selectedDatabase(PdoConnection $connection): string
    {
        $sql = $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? 'SELECT current_database()' : 'SELECT DATABASE()';
        return (string) $connection->pdo->query($sql)->fetchColumn();
    }

    private function seedPostgres(\SisFixture $prototype, array $tables): void
    {
        require_once __DIR__ . '/PostgresQualificationSchema.php';
        if (!$this->schemaReady) {
            $source = $this->settings;
            $source['dsn'] = preg_replace('/;dbname=[^;]+$/D', ';dbname=' . $this->sourceSchema, $source['dsn']);
            $this->source = self::connect($source);
            $this->source->pdo->exec('SET default_transaction_read_only = on');
            $schema = new PostgresQualificationSchema($this->source->pdo, $this->database->pdo);
            $this->seedOrder = $schema->copy($tables);
            $this->schemaReady = true;
        }
        $this->phase = 'seeding';
        $this->writeLease();
        $this->database->pdo->beginTransaction();
        $native = new \Infrastructure\Persistence\SqlRecords($this->database->queryBuilder());
        try {
            $quoted = array_map($native->quote(...), $this->seedOrder);
            // Every fixture relationship is internal. No CASCADE, disabled constraints or application rows.
            $this->database->pdo->exec('TRUNCATE TABLE ' . implode(', ', $quoted));
            $total = 0;
            foreach ($this->seedOrder as $table) {
                $rows = $prototype->sql->rows($table, limit: 1000);
                $total += count($rows);
                if (count($rows) >= 1000 || $total > 2000) {
                    throw new LogicException('The fictional native seed exceeds its row allowance.');
                }
                foreach ($rows as $row) {
                    $native->insert($table, $row);
                }
            }
            $this->database->pdo->commit();
        } catch (Throwable $error) {
            if ($this->database->pdo->inTransaction()) {
                $this->database->pdo->rollBack();
            }
            throw $error;
        }
        \EventStoreFixture::shareConfig(
            $this->database->queryBuilder(),
            \EventStoreFixture::config($prototype->connection->queryBuilder())
        );
        $this->phase = 'ready';
        $this->writeLease();
    }

    private function writeLease(): bool
    {
        return file_put_contents($this->lease, json_encode(['database' => $this->name,
            'owner' => $this->owner, 'phase' => $this->phase], JSON_THROW_ON_ERROR)) !== false;
    }

    public function release(): void
    {
        if (!$this->schemaReady) {
            $this->close();
        } elseif ($this->database->pdo->inTransaction()) {
            $this->database->pdo->rollBack();
        }
    }

    private function verifyForeignKeys(array $counts): void
    {
        $query = $this->database->pdo->prepare('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, '
            . 'REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
            . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
        $query->execute([$this->name]);
        $groups = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $key) {
            if (
                $key['REFERENCED_TABLE_SCHEMA'] !== $this->name ||
                !array_key_exists($key['REFERENCED_TABLE_NAME'], $counts)
            ) {
                throw new LogicException('Qualification foreign keys must remain inside the isolated fixture schema.');
            }
            $groups[$key['TABLE_NAME']][$key['CONSTRAINT_NAME']][] = $key;
        }
        $quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
        foreach ($groups as $table => $constraints) {
            if (($counts[$table] ?? 0) === 0) {
                continue;
            }
            foreach ($constraints as $columns) {
                $join = $present = [];
                foreach ($columns as $column) {
                    $join[] = 'c.' . $quote($column['COLUMN_NAME']) . ' = p.' .
                    $quote($column['REFERENCED_COLUMN_NAME']);
                    $present[] = 'c.' . $quote($column['COLUMN_NAME']) . ' IS NOT NULL';
                }
                $sql = 'SELECT 1 FROM ' . $quote($table) . ' c LEFT JOIN ' .
                $quote($columns[0]['REFERENCED_TABLE_NAME']) . ' p ON ' . implode(' AND ', $join) .
                ' WHERE ' . implode(' AND ', $present) . ' AND p.' .
                $quote($columns[0]['REFERENCED_COLUMN_NAME']) . ' IS NULL LIMIT 1';
                if ($this->database->pdo->query($sql)->fetchColumn() !== false) {
                    throw new LogicException('The fictional seed violates an installed native foreign key.');
                }
            }
        }
    }

    /** Only the owned worker receives this value, through a private pipe; never a process argument. */
    public function workerSettings(): array
    {
        if (!$this->created || !$this->marked) {
            throw new LogicException('The qualification database is unavailable.');
        }
        return $this->settings + ['qualification_owner' => $this->owner];
    }

    public static function workerConnection(#[\SensitiveParameter] array $settings): PdoConnection
    {
        if (
            !preg_match('/;dbname=(etsis_qualify_[a-f0-9]{32})$/D', $settings['dsn'] ?? '', $match) ||
            !preg_match('/^[a-f0-9]{32}$/D', $settings['qualification_owner'] ?? '')
        ) {
            throw new LogicException('Workers may only select a disposable qualification database.');
        }
        $connection = self::connect($settings);
        if (
            self::selectedDatabase($connection) !== $match[1] ||
            $connection->pdo->query('SELECT id FROM etsis_qualification_owner')->fetchAll(PDO::FETCH_COLUMN)
                !== [$settings['qualification_owner']]
        ) {
            throw new LogicException('The worker could not verify ownership of its disposable database.');
        }
        return $connection;
    }

    private static function connect(#[\SensitiveParameter] array $settings): PdoConnection
    {
        $connection = ($settings['driver'] ?? null) === 'pdo_pgsql'
        ? new \Infrastructure\Persistence\PostgresConnection($settings) : DriverConnection::make($settings);
        if (!$connection instanceof PdoConnection) {
            throw new LogicException('Native qualification requires PDO.');
        }
        if ($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $connection->pdo->exec("SET lock_timeout = '3s'");
            $connection->pdo->exec("SET statement_timeout = '5s'");
            $connection->pdo->exec("SET idle_in_transaction_session_timeout = '8s'");
        } else {
            $connection->pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
            $connection->pdo->exec('SET SESSION lock_wait_timeout = 3');
        }
        return $connection;
    }

    public function close(): void
    {
        if (!$this->created) {
            return;
        }
        $this->phase = 'closing';
        $this->writeLease();
        if (!preg_match('/^etsis_qualify_[a-f0-9]{32}$/D', $this->name)) {
            throw new LogicException('Refusing cleanup of an unowned database.');
        }
        if ($this->database?->pdo->inTransaction()) {
            $this->database->pdo->rollBack();
        }
        if (
            $this->marked && (self::selectedDatabase($this->database) !== $this->name ||
            $this->database->pdo->query('SELECT id FROM etsis_qualification_owner')->fetchAll(PDO::FETCH_COLUMN)
                !== [$this->owner])
        ) {
            throw new LogicException('Refusing cleanup without the original qualification ownership marker.');
        }
        $this->database = $this->source = null;
        // PostgreSQL requires all fixture connections closed before dropping its database.
        gc_collect_cycles();
        $quote = $this->driver === 'pgsql' ? '"' : '`';
        // PostgreSQL marks an interrupted DROP invalid. Allow its bounded filesystem cleanup more than a read query.
        if ($this->driver === 'pgsql') {
            $this->server->pdo->exec("SET statement_timeout = '15s'");
        }
        try {
            $this->server->pdo->exec('DROP DATABASE ' . $quote . $this->name . $quote .
                ($this->driver === 'pgsql' ? ' WITH (FORCE)' : ''));
        } finally {
            if ($this->driver === 'pgsql') {
                $this->server->pdo->exec("SET statement_timeout = '5s'");
            }
        }
        $this->created = false;
        $this->database = $this->server = null;
        $this->settings = [];
        if (is_file($this->lease)) {
            unlink($this->lease);
        }
        unset(self::$active[$this]);
        if (self::$shared === $this) {
            self::$shared = null;
        }
        $directory = dirname($this->lease);
        if (is_dir($directory) && count(scandir($directory)) === 2) {
            rmdir($directory);
        }
    }

    public function __debugInfo(): array
    {
        return ['native_qualification' => '[private disposable database]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Native qualification credentials cannot be serialized.');
    }
}
