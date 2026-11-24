<?php

declare(strict_types=1);

namespace Tests\Native;

use Infrastructure\Persistence\PostgresDatabaseConverter;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Explicit opt-in; all writes use generated databases containing fictional rows only. */
final class PostgresDatabaseConversionTest extends TestCase
{
    private ?PDO $source = null;
    private ?PDO $target = null;
    private array $servers = [];
    private array $created = [];
    private ?string $lease = null;

    protected function setUp(): void
    {
        if (getenv('ETSIS_POSTGRES_CONVERSION_TEST') !== '1') {
            self::markTestSkipped('Native conversion qualification requires explicit opt-in.');
        }
        $file = getenv('ETSIS_CONVERSION_TEST_CONFIG');
        if (!is_string($file) || !is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0) {
            throw new LogicException('Use a private native-test connection file.');
        }
        $config = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        $name = 'etsis_convert_' . bin2hex(random_bytes(12));
        $this->lease = dirname($file) . '/' . $name . '.lease';
        file_put_contents($this->lease, $name);
        chmod($this->lease, 0600);
        try {
            foreach (['mysql', 'pgsql'] as $driver) {
                $settings = $config[$driver];
                if (
                    !preg_match(
                        '/^' . $driver . ':host=127\.0\.0\.1;port=[0-9]+;dbname=[a-z0-9_]+$/D',
                        $settings['dsn']
                    )
                ) {
                    throw new LogicException('Use explicit loopback test-server connections.');
                }
                $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_EMULATE_PREPARES => false];
                $server = new PDO($settings['dsn'], $settings['username'], $settings['password'], $options);
                $this->servers[$driver] = $server;
                $quoted = $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
                $server->exec('CREATE DATABASE ' . $quoted . ($driver === 'mysql'
                    ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : ''));
                $this->created[$driver] = $name;
                $dsn = preg_replace('/dbname=[a-z0-9_]+$/D', 'dbname=' . $name, $settings['dsn']);
                $pdo = new PDO(
                    $dsn . ($driver === 'mysql' ? ';charset=utf8mb4' : ''),
                    $settings['username'],
                    $settings['password'],
                    $options
                );
                if ($driver === 'mysql') {
                    $this->source = $pdo;
                } else {
                    $this->target = $pdo;
                    $pdo->exec("SET statement_timeout='5s'");
                    $pdo->exec("SET lock_timeout='2s'");
                }
            }
        } catch (Throwable) {
            $this->tearDown();
            throw new RuntimeException('Cannot prepare isolated conversion fixtures; inspect access privately.');
        }
    }

    protected function tearDown(): void
    {
        $this->source = $this->target = null;
        foreach ($this->created as $driver => $name) {
            if (
                !preg_match('/^etsis_convert_[a-f0-9]{24}$/D', $name) ||
                $this->lease === null || file_get_contents($this->lease) !== $name
            ) {
                throw new LogicException('Retain the private fixture lease for manual cleanup.');
            }
            $quoted = $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
            $this->servers[$driver]->exec('DROP DATABASE ' . $quoted);
            unset($this->created[$driver]);
        }
        $this->servers = [];
        if ($this->lease !== null && is_file($this->lease)) {
            unlink($this->lease);
        }
    }

    public function testConversionPreservesValuesDefaultsIdentityAndReferentialActions(): void
    {
        $this->source->exec('CREATE TABLE parents (id VARCHAR(26) PRIMARY KEY, code VARCHAR(40) NOT NULL, '
            . "notes TEXT NULL, label VARCHAR(40) DEFAULT 'current_timestamp()', "
            . 'amount INT UNSIGNED NOT NULL DEFAULT 0, active TINYINT UNSIGNED NOT NULL DEFAULT 1, '
            . 'huge BIGINT UNSIGNED NOT NULL DEFAULT 18446744073709551615, '
            . 'day DATE NULL, stamp DATETIME(6) NULL, UNIQUE KEY parent_code (code))');
        $this->source->exec('CREATE TABLE children (id VARCHAR(26) PRIMARY KEY, parent_id VARCHAR(26) NOT NULL, '
            . 'CONSTRAINT child_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE)');
        $this->source->exec('CREATE TABLE counters (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(40))');
        $this->source->exec("INSERT INTO parents(id,code,notes,day,stamp) VALUES "
            . "('01AAAAAAAAAAAAAAAAAAAAAAAA','Résumé',NULL,'2026-11-24','2026-11-24 12:34:56.123456'),"
            . "('01BBBBBBBBBBBBBBBBBBBBBBBB','Other','',NULL,NULL)");
        $this->source->exec("INSERT INTO children VALUES ('01CCCCCCCCCCCCCCCCCCCCCCCC','01AAAAAAAAAAAAAAAAAAAAAAAA')");
        $this->source->exec("INSERT INTO counters(id,label) VALUES(12,'Fictional')");
        $converter = new PostgresDatabaseConverter($this->source, $this->target);
        $result = $converter->copy();
        self::assertSame(3, $result['tables']);
        self::assertSame(4, $result['rows']);
        self::assertSame(1, $result['foreign_keys']);
        self::assertFalse($this->source->inTransaction());
        self::assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM parents')->fetchColumn());
        self::assertSame(1, (int) $this->target->query("SELECT COUNT(*) FROM parents WHERE code LIKE 'res%'")
            ->fetchColumn());
        $this->target->exec("INSERT INTO counters(label) VALUES ('Next fictional counter')");
        self::assertSame(13, (int) $this->target->query('SELECT MAX(id) FROM counters')->fetchColumn());
        $this->target->exec("INSERT INTO parents(id,code) VALUES ('01DDDDDDDDDDDDDDDDDDDDDDDD','New')");
        $row = $this->target->query("SELECT amount,active,huge,notes,label FROM parents WHERE code='New'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(
            ['amount' => '0', 'active' => '1', 'huge' => '18446744073709551615', 'notes' => null,
                'label' => 'current_timestamp()'],
            array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row)
        );
        $this->target->exec("DELETE FROM parents WHERE code='Résumé'");
        self::assertSame(0, (int) $this->target->query('SELECT COUNT(*) FROM children')->fetchColumn());
        try {
            $this->target->exec("INSERT INTO parents(id,code) VALUES ('01EEEEEEEEEEEEEEEEEEEEEEEE','other')");
            self::fail('Case-insensitive source uniqueness was lost.');
        } catch (PDOException $error) {
            self::assertSame('23505', $error->getCode());
        }
    }

    public function testUnsupportedSchemaRollsBackAllTargetChangesAndPreservesSource(): void
    {
        $this->source->exec('CREATE TABLE accepted (id INT PRIMARY KEY)');
        $this->source->exec('INSERT INTO accepted VALUES (7)');
        $this->source->exec('CREATE TABLE unsupported (id INT PRIMARY KEY, data BLOB)');
        try {
            new PostgresDatabaseConverter($this->source, $this->target)->copy();
            self::fail('Unsupported schema was silently converted.');
        } catch (LogicException) {
            self::assertSame(7, (int) $this->source->query('SELECT id FROM accepted')->fetchColumn());
            self::assertSame(0, (int) $this->target->query("SELECT COUNT(*) FROM information_schema.tables "
                . "WHERE table_schema='public'")->fetchColumn());
            self::assertFalse($this->target->inTransaction());
            self::assertFalse($this->source->inTransaction());
        }
    }

    public function testPopulatedTargetIsNeverOverwritten(): void
    {
        $this->target->exec('CREATE TABLE retained (id INTEGER PRIMARY KEY)');
        $this->target->exec('INSERT INTO retained VALUES (9)');
        try {
            new PostgresDatabaseConverter($this->source, $this->target)->copy();
            self::fail('Populated target was accepted.');
        } catch (LogicException) {
            self::assertSame(9, (int) $this->target->query('SELECT id FROM retained')->fetchColumn());
        }
    }
}
