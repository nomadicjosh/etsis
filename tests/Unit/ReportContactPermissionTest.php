<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Access\Enum\GrantKind;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\StudentContactReportSchema;
use LogicException;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class ReportContactPermissionTest extends TestCase
{
    public function testPermissionMigrationIsIdempotentProtectedAndGrantsNoRoleOrUserAccess(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $s = new \SisFixture();
        $tables = ['sis_access_permissions', 'sis_access_implications', 'sis_access_role_permissions',
            'sis_access_user_roles', 'sis_access_user_permissions', 'sis_history_events'];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \AddSisReportContactPermission('20261126000006');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($s->connection, 'migration')]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $p = $s->sql->one('sis_access_permissions', ['name' => StudentContactReportSchema::PERMISSION]);
        self::assertSame(1, (int) $p['is_system']);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $p['id']);
        self::assertSame([], $s->sql->rows('sis_access_role_permissions', ['permission_id' => $p['id']]));
        self::assertSame([], $s->sql->rows('sis_access_user_permissions', ['permission_id' => $p['id']]));
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(GrantKind::UserRole, $s->access->actor, $roles->roleNamed('admin')->id, true);
        self::assertFalse($roles->allows($s->access->actor->value, StudentContactReportSchema::PERMISSION));
        $roles->assign(GrantKind::UserPermission, $s->access->actor, new EntityId($p['id']), true);
        self::assertTrue($roles->allows($s->access->actor->value, StudentContactReportSchema::PERMISSION));
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function testCustomPermissionConflictDoesNotConvertTheDefinitionOrChangeGrants(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $s = new \SisFixture();
        $s->sql->update(
            'sis_access_permissions',
            ['is_system' => 0],
            ['name' => StudentContactReportSchema::PERMISSION]
        );
        $before = [$s->sql->rows('sis_access_permissions'), $s->sql->rows('sis_access_implications')];
        $migration = new \AddSisReportContactPermission('20261126000006');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($s->connection, 'migration')]));
        try {
            $migration->up();
            self::fail('An existing custom permission was silently converted.');
        } catch (LogicException) {
            self::assertSame($before, [$s->sql->rows('sis_access_permissions'),
                $s->sql->rows('sis_access_implications')]);
        }
    }

    public function testContactJoinAndHiddenFiltersCompileAcrossFiveSupportedDialects(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $pdo = $this->createStub(\PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method(\PHPUnit\Framework\MockObject\Runtime\PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
                    'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"',
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $query = new ReportQuery(['dataset' => 'students', 'columns' => [
                ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'count_distinct'],
            ], 'existence' => ['account_contact' => ['mode' => 'any', 'filters' => [
                'condition' => 'AND', 'rules' => [
                    ['id' => 'current_account_email', 'operator' => 'equal', 'value' => 'fictional@example.invalid'],
                ],
            ]]]], AcademicReportSchema::datasets()['students']);
            $plan = new ReportSelectCompiler(new SqlRecords($db))->compile($query);
            self::assertSame(['fictional@example.invalid'], $plan['parameters']);
            self::assertContains(StudentContactReportSchema::PERMISSION, $query->permissions());
            self::assertStringContainsString('owner_user_id', $plan['sql']);
            self::assertStringContainsString('user_id', $plan['sql']);
            self::assertStringNotContainsString('fictional@example.invalid', $plan['sql']);
            self::assertStringNotContainsString('password', $plan['sql']);
            self::assertDoesNotMatchRegularExpression('/SELECT \* FROM [`"\[]?users\b/i', $plan['sql']);
        }
    }

    public function testOnlyApprovedCurrentStudentRelatedSourcesGainTheContactField(): void
    {
        $ids = [];
        foreach (AcademicReportSchema::datasets() as $id => $schema) {
            if (isset($schema['fields']['current_account_email'])) {
                $ids[] = $id;
                self::assertSame(['sis:reporting:contact'], $schema['fields']['current_account_email']['permissions']);
                self::assertFalse($schema['relationships']['account_contact']['many'] ?? false);
                self::assertSame('users', $schema['relationships']['account_contact']['table']);
            }
        }
        self::assertEqualsCanonicalizing(['students', 'programs', 'applications', 'credits', 'accounts'], $ids);
    }
}
