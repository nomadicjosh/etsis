<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MysqlQualificationDatabase;

final class MysqlQualificationDatabaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/MysqlQualificationDatabase.php';
    }

    public function testTargetSubstitutionPreservesServerAndPrivateCredentialsWithoutSelectingTheApplication(): void
    {
        $original = ['dsn' => 'mysql:host=127.0.0.1;port=3307;dbname=fictional_sis;charset=utf8mb4',
            'username' => 'fictional', 'password' => 'FICTIONAL_PRIVATE_PASSWORD'];
        $target = 'etsis_qualify_' . str_repeat('a', 32);
        $settings = MysqlQualificationDatabase::settingsFor($original, $target);
        self::assertSame('mysql:host=127.0.0.1;port=3307;charset=utf8mb4;dbname=' . $target, $settings['dsn']);
        self::assertSame($original['username'], $settings['username']);
        self::assertSame($original['password'], $settings['password']);
        self::assertFalse($settings['options'][\PDO::ATTR_PERSISTENT]);
        self::assertFalse($settings['options'][\PDO::ATTR_EMULATE_PREPARES]);
    }

    public static function invalidTargets(): array
    {
        $target = 'etsis_qualify_' . str_repeat('a', 32);
        return [
            ['mysql:host=localhost;dbname=fictional_sis', 'fictional_sis'],
            ['mysql:host=localhost;dbname=fictional_sis', $target . '`'],
            ['mysql:host=localhost;dbname=fictional_sis', 'etsis_qualify_short'],
            ['mysql:host=localhost;dbname=fictional_sis;dbname=other', $target],
            ['mysql:host=localhost;dbname=fictional_sis;untrusted=anything', $target],
            ['mysql:host=localhost', $target],
            ['mysql:dbname=fictional_sis', $target],
            ['pgsql:host=localhost;dbname=fictional_sis', $target],
            ['mysql:host=localhost;dbname=' . $target, $target],
            ["mysql:host=localhost;dbname=fictional_sis\n", $target],
        ];
    }

    public function testMappedDevelopmentEndpointChangesOnlyTheExplicitConnectionEndpoint(): void
    {
        $settings = MysqlQualificationDatabase::settingsFor(
            ['dsn' => 'mysql:host=db;dbname=fictional_sis', 'username' => 'fictional', 'password' => 'PRIVATE'],
            'etsis_qualify_' . str_repeat('a', 32),
            '127.0.0.1:3307'
        );
        self::assertSame(
            'mysql:host=127.0.0.1;port=3307;dbname=etsis_qualify_' . str_repeat('a', 32),
            $settings['dsn']
        );
        self::assertSame('PRIVATE', $settings['password']);
        foreach (
            ['localhost:3307', '127.0.0.1:0', '127.0.0.1:65536', 'remote.invalid:3307',
            '127.0.0.1:3307;dbname=fictional_sis'] as $endpoint
        ) {
            try {
                MysqlQualificationDatabase::settingsFor(
                    ['dsn' => 'mysql:host=db;dbname=fictional_sis'],
                    'etsis_qualify_' . str_repeat('a', 32),
                    $endpoint
                );
                self::fail('An invalid mapped endpoint was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('invalidTargets')]
    public function testUnsafeTargetsAndAmbiguousOrMismatchedDsnsFailBeforeAnyConnection(
        string $dsn,
        string $target
    ): void {
        $this->expectException(LogicException::class);
        MysqlQualificationDatabase::settingsFor(['dsn' => $dsn], $target);
    }

    public function testNativeExecutionCannotStartWithoutExplicitOptIn(): void
    {
        $previous = getenv('ETSIS_WORKFLOW_MYSQL_QUALIFICATION');
        putenv('ETSIS_WORKFLOW_MYSQL_QUALIFICATION=0');
        try {
            $this->expectException(LogicException::class);
            new MysqlQualificationDatabase();
        } finally {
            putenv($previous === false ? 'ETSIS_WORKFLOW_MYSQL_QUALIFICATION' :
                'ETSIS_WORKFLOW_MYSQL_QUALIFICATION=' . $previous);
        }
    }

    public function testWorkersRejectAnApplicationTargetAndCredentialsCannotBeSerialized(): void
    {
        foreach (
            ['mysql:host=localhost;dbname=fictional_sis',
            'mysql:host=localhost;dbname=etsis_qualify_' . str_repeat('a', 32)] as $dsn
        ) {
            try {
                MysqlQualificationDatabase::workerConnection(['dsn' => $dsn]);
                self::fail('An unowned worker target was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        $object = new \ReflectionClass(MysqlQualificationDatabase::class)->newInstanceWithoutConstructor();
        self::assertSame(['native_qualification' => '[private disposable database]'], $object->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($object);
    }
}
