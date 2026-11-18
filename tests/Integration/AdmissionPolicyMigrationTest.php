<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Infrastructure\Persistence\SqlRecords;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class AdmissionPolicyMigrationTest extends TestCase
{
    public function testConfigurationConversionPreservesTheExistingOfferPolicyAndRevision(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/database/migrations/20261117000007_CreateSisAdmissionWithdrawalRequests.php';
        $db = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $db->pdo->exec('CREATE TABLE sis_applications (id VARCHAR(26) PRIMARY KEY)');
        $db->pdo->exec('CREATE TABLE sis_admission_offer_policy '
            . '(id VARCHAR(26) PRIMARY KEY, require_acceptance INTEGER, revision INTEGER)');
        $db->pdo->exec('CREATE TABLE sis_access_permissions '
            . '(id VARCHAR(26) PRIMARY KEY, name VARCHAR(120), is_system INTEGER, description TEXT)');
        $db->pdo->exec('CREATE TABLE retained_fixture_facts (id VARCHAR(26) PRIMARY KEY, value TEXT)');
        $sql = new SqlRecords($db->queryBuilder());
        $sql->insert('sis_admission_offer_policy', ['id' => AdmissionPolicyRepository::ID,
            'require_acceptance' => 0, 'revision' => 7]);
        $sql->insert('sis_access_permissions', ['id' => AdmissionPolicyRepository::ID,
            'name' => 'sis:admissions:policy', 'is_system' => 1, 'description' => 'Earlier policy label.']);
        $sql->insert(
            'retained_fixture_facts',
            ['id' => AdmissionPolicyRepository::ID, 'value' => 'Original fictional fact.']
        );
        $before = $sql->rows('retained_fixture_facts');
        $m = new \CreateSisAdmissionWithdrawalRequests('test');
        $m->setObjectMap(new \ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($db, 'test_migrations')]));
        $m->up();
        $policy = new \Infrastructure\Persistence\Repository\SqlAdmissionPolicyRepository($sql);
        self::assertSame(['require_acceptance' => false, 'revision' => 7], $policy->offers());
        self::assertSame(['mode' => 'request', 'revision' => 0], $policy->withdrawals());
        self::assertFalse($sql->tableExists('sis_admission_offer_policy'));
        self::assertTrue($sql->tableExists('sis_admission_withdrawal_requests'));
        self::assertSame($before, $sql->rows('retained_fixture_facts'));
    }
}
