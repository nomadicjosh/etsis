<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Infrastructure\Security\ConfiguredEmailVerificationPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\EmailVerificationFixture;

final class EmailVerificationMigrationPolicyTest extends TestCase
{
    public function testOptionalConfigurationDefaultsOffAndUsesStrictBooleans(): void
    {
        $config = Collection::factory([]);
        $policy = new ConfiguredEmailVerificationPolicy($config);
        self::assertFalse($policy->requiredForApplications());
        self::assertFalse($policy->deliveryEnabled());
        $config->setConfigKey('auth', ['application_email_verification_required' => true]);
        self::assertTrue($policy->requiredForApplications());
        $config->setConfigKey('auth', ['application_email_verification_required' => 'false']);
        $this->expectException(InvalidArgumentException::class);
        $policy->requiredForApplications();
    }

    #[DataProvider('invalidOrigins')]
    public function testPrivateLinksRequireACanonicalHttpsOrigin(string $origin): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['url' => $origin]);
        $this->expectException(InvalidArgumentException::class);
        new ConfiguredEmailVerificationPolicy($config)->origin();
    }
    public static function invalidOrigins(): array
    {
        return [['http://campus.example.invalid'], ['https://private:secret@campus.example.invalid'],
            ['https://campus.example.invalid?token=fictional'], ['https://campus.example.invalid/#fictional'],
            ["https://campus.example.invalid/\r\n"], ['//campus.example.invalid']];
    }

    public function testProofStorageCompilesOnFiveDialectsWithoutGrantsOrHistoricalBackfill(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261127000004_CreateSisEmailVerifications.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"'
        );
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_email_verifications');
            \CreateSisEmailVerifications::define($table);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', $sql);
            self::assertStringContainsString('sev_pending_idx', $sql);
            self::assertStringContainsString('sev_expiry_idx', $sql);
            self::assertDoesNotMatchRegularExpression(
                '/INSERT INTO|UPDATE |DELETE FROM|COLLATE|AUTO_INCREMENT/i',
                $sql
            );
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }

    public function testRepeatingTheMigrationPreservesCurrentProofAndEveryBusinessFact(): void
    {
        require_once dirname(__DIR__) . '/Support/EmailVerificationFixture.php';
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->service()->confirm($f->token());
        $tables = [
            'sis_email_verifications', 'users', 'sis_persons', 'sis_history_events', 'sis_access_user_permissions',
        ];
        $before = array_map($f->sis->sql->rows(...), $tables);
        $migration = new \CreateSisEmailVerifications('20261127000004');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->sis->connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sis->sql->rows(...), $tables));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
