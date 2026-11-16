<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\Repository\HrLibraryRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlHrLibraryRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class HrLibraryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function grade(string $code = 'H.1', bool $active = true, ?EntityId $id = null): PayGrade
    {
        return new PayGrade(
            $id ?? EntityId::generate(),
            $code,
            'Fictional hourly grade',
            'USD',
            'hourly',
            1500,
            5000,
            $active
        );
    }
    public function testDefinitionsAreAuditedCrudWithNumericRevisionsAndPreserveAllPersonAndEventFacts(): void
    {
        $s = new SisFixture();
        $employee = $s->timesheetService()->addEmployee($s->student()->personId, 'Existing employee title');
        $service = $s->hrLibrary();
        $grade = $this->grade(' h.1 ');
        $tables = ['sis_employees', 'sis_timesheets', 'sis_history_events',
            'sis_history_operations', 'sis_persons', 'users'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        self::assertSame('H.1', $service->save($grade, null)['code']);
        $job = new JobDefinition(
            EntityId::generate(),
            'lib/asst',
            'Library assistant',
            'USD',
            'hourly',
            true,
            $grade->id,
            2500,
            2250
        );
        $saved = $service->save($job, null);
        self::assertSame(0, $saved['version']);
        self::assertSame(2250, $saved['weekly_minutes']);
        self::assertSame(2500, $saved['rate_minor']);
        $audit = $s->sql->rows('sis_audit');
        self::assertSame($saved, $service->save($job, 0));
        self::assertSame($audit, $s->sql->rows('sis_audit'));
        $next = new JobDefinition(
            $job->id,
            $job->code,
            'Revised default title',
            'USD',
            'hourly',
            false,
            $grade->id,
            2600,
            1800
        );
        self::assertSame(1, $service->save($next, 0)['version']);
        self::assertSame('Existing employee title', $s->timesheetService()->employeeDetails($employee)['job_title']);
        self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        foreach (['Library assistant', 'Revised default title', 'rate_minor', 'weekly_minutes'] as $private) {
            self::assertStringNotContainsString($private, json_encode($s->sql->rows('sis_audit'), JSON_THROW_ON_ERROR));
        }
        $this->expectException(ConcurrentModificationException::class);
        $service->save($job, 0);
    }
    public function testGradeChangesCannotInvalidateActiveJobsAndRatesUseMatchingUnits(): void
    {
        $s = new SisFixture();
        $service = $s->hrLibrary();
        $grade = $this->grade();
        $service->save($grade, null);
        $invalidUnits = [['EUR', 'hourly', 2500], ['USD', 'annual', 2500], ['USD', 'hourly', 1000]];
        foreach ($invalidUnits as [$currency, $basis, $rate]) {
            try {
                $service->save(new JobDefinition(
                    EntityId::generate(),
                    'INVALID',
                    'Fictional default',
                    $currency,
                    $basis,
                    true,
                    $grade->id,
                    $rate
                ), null);
                self::fail('Incompatible job defaults were accepted.');
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows('sis_hr_jobs'));
            }
        }
        $job = new JobDefinition(EntityId::generate(), 'JOB', 'Fictional job', 'USD', 'hourly', true, $grade->id, 2500);
        $service->save($job, null);
        foreach (
            [$this->grade(active: false, id: $grade->id),
            new PayGrade($grade->id, 'H.1', 'Smaller range', 'USD', 'hourly', 3000, 5000, true),
            new PayGrade($grade->id, 'H.1', 'Annual range', 'USD', 'annual', 1500, 5000, true)] as $change
        ) {
            try {
                $service->save($change, 0);
                self::fail('A grade change invalidated an active linked job.');
            } catch (ValidationException) {
                self::assertSame(0, $service->get(HrLibraryKind::PayGrades, $grade->id)['version']);
            }
        }
        $retired = new JobDefinition($job->id, 'JOB', 'Fictional job', 'USD', 'hourly', false, $grade->id, 2500);
        $service->save($retired, 0);
        $service->save($this->grade(active: false, id: $grade->id), 0);
        $this->expectException(ValidationException::class);
        $service->save($job, 1);
    }
    public function testDefinitionsValidateIsoCurrencyFractionMetadataAndNeverAssumeTwoDecimalPlaces(): void
    {
        $s = new SisFixture();
        $service = $s->hrLibrary();
        foreach ([['JPY', 0], ['BHD', 3], ['USD', 2]] as [$code, $digits]) {
            $grade = new PayGrade(
                EntityId::generate(),
                $code,
                'Fictional currency grade',
                $code,
                'annual',
                0,
                123456,
                true
            );
            $service->save($grade, null);
            self::assertSame(123456, $service->get(HrLibraryKind::PayGrades, $grade->id)['maximum_minor']);
            $options = array_column($service->page(HrLibraryKind::PayGrades)['currencies'], 'digits', 'code');
            self::assertSame($digits, $options[$code]);
        }
        foreach (
            [['USD', 'weekly', 0, 1], ['ZZZ', 'annual', 0, 1], ['USD', 'annual', 5, 1],
            ['USD', 'annual', -1, 1], ['USD', 'annual', 0, 2000000001]] as [$currency, $basis, $min, $max]
        ) {
            try {
                new PayGrade(EntityId::generate(), 'INVALID', 'Invalid grade', $currency, $basis, $min, $max, true);
                self::fail('Invalid pay range or units were accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        new JobDefinition(EntityId::generate(), 'JOB', 'Fictional job', 'USD', 'hourly', true, weeklyMinutes: 10081);
    }

    public function testMonthlyGradesAndJobsRetainExactRatesAndRequireMatchingUnits(): void
    {
        $s = new SisFixture();
        $service = $s->hrLibrary();
        $before = $s->sql->rows('sis_history_events');
        $grade = new PayGrade(
            EntityId::generate(),
            'M.1',
            'Fictional monthly grade',
            'USD',
            'monthly',
            300000,
            800000,
            true
        );
        $service->save($grade, null);
        $job = new JobDefinition(
            EntityId::generate(),
            'MONTHLY',
            'Fictional monthly job',
            'USD',
            'monthly',
            true,
            $grade->id,
            450050,
            2250
        );
        $service->save($job, null);
        self::assertSame('monthly', $service->get(HrLibraryKind::PayGrades, $grade->id)['basis']);
        self::assertSame('monthly', $service->get(HrLibraryKind::Jobs, $job->id)['basis']);
        self::assertSame(450050, $service->get(HrLibraryKind::Jobs, $job->id)['rate_minor']);
        foreach (['hourly', 'annual'] as $basis) {
            try {
                $service->save(new JobDefinition(
                    EntityId::generate(),
                    strtoupper($basis),
                    'Mismatched job',
                    'USD',
                    $basis,
                    true,
                    $grade->id,
                    450050
                ), null);
                self::fail('A job with another rate basis used a monthly grade.');
            } catch (ValidationException) {
                self::assertCount(1, $service->page(HrLibraryKind::Jobs)['items']);
            }
        }
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $this->expectException(ValidationException::class);
        $service->save(new PayGrade(
            $grade->id,
            'M.1',
            'Changed grade',
            'USD',
            'annual',
            300000,
            800000,
            true
        ), 0);
    }
    public function testSearchAndKeysetPagesAreBoundedAndNormalizeDuplicateCodes(): void
    {
        $s = new SisFixture();
        $service = $s->hrLibrary();
        for ($i = 0; $i < 52; ++$i) {
            $service->save($this->grade('G.' . $i), null);
        }
        $first = $service->page(HrLibraryKind::PayGrades);
        self::assertCount(50, $first['items']);
        $last = $service->page(HrLibraryKind::PayGrades, after: new EntityId($first['next_after']));
        self::assertCount(2, $last['items']);
        self::assertNull($last['next_after']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($last['items'], 'id')));
        self::assertCount(1, $service->page(HrLibraryKind::PayGrades, 'G.51')['items']);
        $this->expectException(ValidationException::class);
        $service->save($this->grade(' g.51 '), null);
    }
    public function testRequiredAuditOrChangedAuthorityRollsBackReferenceChanges(): void
    {
        foreach (['audit', 'actor', 'permission'] as $case) {
            $s = new SisFixture();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $case): void {
                if ($case === 'audit') {
                    throw new RuntimeException('Fictional audit failure');
                }
                if ($case === 'actor') {
                    $s->access->actor = EntityId::generate();
                } else {
                    $s->access->staff = false;
                }
            });
            $tables = ['sis_hr_pay_grades', 'sis_hr_jobs', 'sis_audit', 'sis_history_events'];
            $before = array_map(fn($t) => $s->sql->rows($t), $tables);
            try {
                $s->hrLibrary(audit: $audit)->save($this->grade(), null);
                self::fail('A failed HR setup mutation committed.');
            } catch (RuntimeException) {
                self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
            }
        }
    }
    public function testPermissionIsRecheckedAfterWaitingForTheAccessLock(): void
    {
        $s = new SisFixture();
        $roles = $this->createStub(\Domain\Access\Repository\AccessRepository::class);
        $roles->method('lock')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
        });
        $this->expectException(AccessDeniedException::class);
        $s->hrLibrary(roles: $roles)->save($this->grade(), null);
    }
    public function testReadsRecheckAuthorityAndOrdinaryRecordOwnershipGrantsNoSetupAccess(): void
    {
        $s = new SisFixture();
        $real = new SqlHrLibraryRepository($s->sql);
        $rows = $this->createStub(HrLibraryRepository::class);
        $rows->method('page')->willReturnCallback(static function () use ($s, $real): array {
            $result = $real->page(HrLibraryKind::Jobs, '', null);
            $s->access->staff = false;
            return $result;
        });
        try {
            $s->hrLibrary(rows: $rows)->page(HrLibraryKind::Jobs);
            self::fail('A revoked HR read returned setup data.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $this->expectException(AccessDeniedException::class);
        $s->hrLibrary()->save($this->grade(), null);
    }
}
