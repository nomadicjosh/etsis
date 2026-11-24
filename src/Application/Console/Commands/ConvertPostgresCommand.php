<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Operations\MaintenanceMode;
use Dotenv\Dotenv;
use Infrastructure\Persistence\PostgresDatabaseConverter;
use LogicException;
use PDO;
use Throwable;

/** Explicit deployment operation; source is preserved and no business events are fabricated. */
final class ConvertPostgresCommand extends ConsoleCommand
{
    protected string $name = 'sis:database:postgres';
    protected string $description = 'Copy and verify a bounded MariaDB database into an empty PostgreSQL target.';
    protected array $options = [
        ['target-file', null, 'required', 'Private target connection file under storage/app/database-migration.'],
        ['source-endpoint', null, 'required', 'Optional published loopback endpoint, 127.0.0.1:port.'],
        ['switch', null, 'none', 'Switch the application connection only after complete verification and backup.'],
    ];

    public function __construct(
        Application $codefy,
        private readonly MaintenanceMode $maintenance,
        private readonly MessageCipher $cipher,
    ) {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        $converter = null;
        $gate = null;
        try {
            if ($this->maintenance->status()->status !== 'down') {
                throw new LogicException('Enable maintenance before conversion.');
            }
            $root = $this->codefy->basePath();
            $directory = $this->codefy->storagePath() . '/app/database-migration';
            $path = $this->getOptions('target-file');
            if (
                !is_string($path) || !is_file($path) || !is_readable($path) || is_link($path) ||
                realpath(dirname($path)) !== realpath($directory) ||
                (fileperms($path) & 0077) !== 0
            ) {
                throw new LogicException('Use the private target configuration.');
            }
            $target = json_decode(file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
            $environment = Dotenv::createArrayBacked($root)->load();
            if (
                ($environment['DB_CONNECTION'] ?? null) !== 'mysql' ||
                !str_starts_with($target['dsn'] ?? '', 'pgsql:')
            ) {
                throw new LogicException('Select the source and target families explicitly.');
            }
            $sourceDsn = $environment['DB_DSN'];
            $endpoint = $this->getOptions('source-endpoint');
            if ($endpoint !== null) {
                if (
                    !is_string($endpoint) || !preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D', $endpoint, $m) ||
                    (int) $m[1] < 1 || (int) $m[1] > 65535
                ) {
                    throw new LogicException('Use an explicit source loopback endpoint.');
                }
                $sourceDsn = preg_replace('/host=[^;]+/', 'host=127.0.0.1', $sourceDsn);
                $sourceDsn = preg_replace('/port=[^;]+/', 'port=' . $m[1], $sourceDsn);
            }
            if (!str_contains($sourceDsn, 'charset=')) {
                $sourceDsn .= ';charset=utf8mb4';
            }
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_EMULATE_PREPARES => false];
            $source = new PDO($sourceDsn, $environment['DB_USER'], $environment['DB_PASSWORD'], $options);
            $gate = new PDO($sourceDsn, $environment['DB_USER'], $environment['DB_PASSWORD'], $options);
            $gate->exec('SET SESSION lock_wait_timeout = 3');
            $tables = $source->query('SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
            $locks = [];
            foreach ($tables as $table) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table)) {
                    throw new LogicException('Review the source identifiers before locking.');
                }
                $locks[] = '`' . $table . '` READ';
            }
            $gate->exec('LOCK TABLES ' . implode(', ', $locks));
            $originalEnvironment = file_get_contents($root . '/.env');
            $destination = new PDO($target['dsn'], $target['username'], $target['password'], $options);
            $destination->exec("SET statement_timeout = '10s'");
            $destination->exec("SET lock_timeout = '3s'");
            $this->terminalRaw('Converting an empty PostgreSQL target with a read-only source snapshot.');
            $converter = new PostgresDatabaseConverter($source, $destination);
            $result = $converter->copy();
            $mask = umask(0077);
            try {
                $backup = $this->cipher->seal(json_encode($result['backup'], JSON_THROW_ON_ERROR));
                if (
                    file_put_contents($directory . '/mariadb-snapshot.enc', $backup) === false ||
                    file_put_contents(
                        $directory . '/mariadb-environment.enc',
                        $this->cipher->seal($originalEnvironment)
                    ) === false
                ) {
                    throw new LogicException('Preserve the rollback artifacts before switching.');
                }
                unset($result['backup']);
                if (
                    file_put_contents(
                        $directory . '/verified-manifest.enc',
                        $this->cipher->seal(json_encode($result, JSON_THROW_ON_ERROR))
                    ) === false
                ) {
                    throw new LogicException('Preserve the verification receipt before switching.');
                }
                if ($this->getOptions('switch') === true) {
                    if (
                        !hash_equals(
                            hash('sha256', $originalEnvironment),
                            hash_file('sha256', $root . '/.env')
                        ) ||
                        !str_starts_with($target['application_dsn'] ?? '', 'pgsql:')
                    ) {
                        throw new LogicException('The application configuration changed during conversion.');
                    }
                    $next = $originalEnvironment;
                    foreach (
                        ['DB_CONNECTION' => 'pgsql', 'DB_DSN' => $target['application_dsn'],
                        'DB_USER' => $target['username'], 'DB_PASSWORD' => $target['password']] as $key => $value
                    ) {
                        if (preg_match('/[\r\n\x00"\\\\]/', $value)) {
                            throw new LogicException('Review the target environment encoding.');
                        }
                        $next = preg_replace('/^' . $key . '\s*=.*$/m', $key . '="' . $value . '"', $next, 1, $count);
                        if ($count !== 1) {
                            throw new LogicException('Review the application database configuration.');
                        }
                    }
                    if (
                        file_put_contents(
                            $directory . '/postgres-environment.enc',
                            $this->cipher->seal($next)
                        ) === false
                    ) {
                        throw new LogicException('Preserve the target environment before switching.');
                    }
                    $temporary = $root . '/.env.postgres-' . bin2hex(random_bytes(6));
                    if (
                        file_put_contents($temporary, $next) === false || !chmod($temporary, 0600) ||
                        !rename($temporary, $root . '/.env')
                    ) {
                        throw new LogicException('The verified target could not become the application connection.');
                    }
                }
            } finally {
                umask($mask);
            }
            $this->terminalRaw('Verified ' . $result['tables'] . ' tables, ' . $result['rows'] .
                ' rows and ' . $result['foreign_keys'] . ' foreign keys.');
            $this->terminalRaw($this->getOptions('switch') === true
                ? 'Encrypted rollback artifacts saved. The application now uses PostgreSQL.'
                : 'Encrypted rollback artifacts saved. The application connection has not switched.');
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->terminalRaw('Conversion stopped safely at ' . ($converter?->phase ?? 'configuration') .
                ($converter?->table === null ? '' : ' / ' . $converter->table) . '.');
            $this->terminalRaw('Private error category: ' . get_class($error) .
                ($error instanceof \PDOException ? ' / ' . (string) $error->getCode() : '') . '.');
            return self::FAILURE;
        } finally {
            if ($gate !== null) {
                try {
                    $gate->exec('UNLOCK TABLES');
                } catch (Throwable) {
                    // Closing a failed connection also releases its session-owned read locks.
                    $gate = null;
                }
            }
        }
    }
}
