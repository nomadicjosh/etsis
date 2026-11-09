<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\Service\TimesheetService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use PHPUnit\Framework\TestCase;

final class EmploymentManagementTest extends TestCase
{
    private function service(SisFixture $f, ?AuditTrail $audit = null, ?TimeRepository $repository = null): TimesheetService
    {
        return new TimesheetService(
            $repository ?? new SqlTimeRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    private function employee(SisFixture $f, TimesheetService $service): EntityId
    {
        return $service->addEmployee($f->student()->personId, 'Fictional librarian');
    }

    private function work(int $offset = 0): TimeSlot
    {
        $start = new DateTimeImmutable('2026-08-11T09:00:00-07:00')->modify('+' . $offset . ' hours');
        return new TimeSlot($start, $start->modify('+1 hour'));
    }

    public function testEmploymentEditsRejectStaleSavesAndKeepHistoricalWorkReadable(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $entry = $service->submit($id, $this->work());
        $before = $service->employeeDetails($id);
        self::assertTrue($before['is_self']);
        $service->editEmployee($id, 'Senior librarian', false, $before['revision']);
        $changed = $service->employeeDetails($id);
        self::assertFalse($changed['active']);
        self::assertSame($before['person_id'], $changed['person_id']);
        self::assertSame($before['institutional_id'], $changed['institutional_id']);
        self::assertSame('pending', $service->get($entry->id)->status);
        self::assertCount(1, $service->entries($id)['items']);
        try {
            $service->submit($id, $this->work(2));
            self::fail('Inactive employment accepted new work.');
        } catch (DomainException) {
            self::assertCount(1, $f->sql->rows('sis_timesheets'));
        }
        try {
            $service->editEmployee($id, 'Stale title', true, $before['revision']);
            self::fail('Stale employment edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($service->employeeDetails($id)['active']);
        }
        $service->editEmployee($id, 'Senior librarian', true, $changed['revision']);
        self::assertSame($id->value, $service->submit($id, $this->work(2))->employeeId->value);
        self::assertStringNotContainsString('librarian', json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR));
    }

    public function testSupervisorCanDecideHistoricalWorkAfterDeactivationButOwnerCannotSelfApprove(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $entry = $service->submit($id, $this->work());
        $service->editEmployee($id, 'Fictional librarian', false, $service->employeeDetails($id)['revision']);
        try {
            $service->decide($entry->id, true, 0);
            self::fail('A staff owner approved their own time.');
        } catch (DomainException) {
            self::assertSame('pending', $service->get($entry->id)->status);
        }
        $f->access->actor = $f->user();
        self::assertFalse($service->employeeDetails($id)['is_self']);
        $service->decide($entry->id, true, 0);
        self::assertSame('approved', $service->get($entry->id)->status);
        self::assertSame(1, $service->get($entry->id)->version);
        try {
            $service->decide($entry->id, false, 0);
            self::fail('A stale decision replaced approved work.');
        } catch (ConcurrentModificationException) {
            self::assertSame('approved', $service->get($entry->id)->status);
        }
    }

    public function testOwnerReadsAreScopedAndDoNotGrantEmploymentMaintenance(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $entry = $service->submit($id, $this->work());
        $f->access->staff = false;
        self::assertTrue($service->employeeDetails($id)['is_self']);
        self::assertCount(1, $service->entries($id)['items']);
        try {
            $service->editEmployee($id, 'Unauthorized', false, $service->employeeDetails($id)['revision']);
            self::fail('Record ownership granted HR maintenance.');
        } catch (AccessDeniedException) {
            self::assertTrue($service->employeeDetails($id)['active']);
        }
        $f->access->actor = $f->user();
        foreach (
            [fn () => $service->employeeDetails($id), fn () => $service->entries($id),
            fn () => $service->get($entry->id)] as $read
        ) {
            try {
                $read();
                self::fail('Another user accessed private employment.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTimeHistoryIsPagedAndUsesExplicitUtcOffsetsAndElapsedDurations(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        for ($i = 0; $i < 51; ++$i) {
            $latest = $service->submit($id, $this->work($i));
        }
        $first = $service->entries($id);
        self::assertCount(50, $first['items']);
        self::assertSame($latest->id->value, $first['items'][0]['id']);
        self::assertSame(3600, $first['items'][0]['duration_seconds']);
        self::assertStringEndsWith('+00:00', $first['items'][0]['starts_at']);
        $second = $service->entries($id, new EntityId($first['next_after']));
        self::assertCount(1, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $other = $this->employee($f, $service);
        self::assertSame([], $service->entries($other)['items']);
    }

    public function testDuplicateEmploymentAndInvalidJobTitlesHaveSafeFieldErrors(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $profile = $service->employeeDetails($id);
        foreach (
            [fn () => $service->addEmployee(new EntityId($profile['person_id']), 'Duplicate'),
            fn () => $service->editEmployee($id, "Bad\nTitle", true, $profile['revision'])] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Invalid employment mutation was accepted.');
            } catch (ValidationException $error) {
                self::assertContains($error->errors[0]['field'], ['person_id', 'job_title']);
                self::assertCount(1, $f->sql->rows('sis_employees'));
                self::assertSame('Fictional librarian', $service->employeeDetails($id)['job_title']);
            }
        }
        $this->expectException(RecordNotFoundException::class);
        $service->addEmployee(EntityId::generate(), 'Fictional job');
    }

    public function testEmploymentEditRollsBackWhenItsAuditCannotBeWritten(): void
    {
        $f = new SisFixture();
        $id = $this->employee($f, $this->service($f));
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('fictional failure'));
        $service = $this->service($f, $audit);
        try {
            $service->editEmployee($id, 'Changed', false, $service->employeeDetails($id)['revision']);
            self::fail('Unaudited employment edit committed.');
        } catch (RuntimeException) {
            self::assertSame('Fictional librarian', $service->employeeDetails($id)['job_title']);
            self::assertTrue($service->employeeDetails($id)['active']);
        }
    }

    public function testSubmissionRechecksActivityAfterAcquiringItsLock(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $real = new SqlTimeRepository($f->sql);
        $repository = $this->createMock(TimeRepository::class);
        $repository->method('employeePerson')->willReturnCallback(fn ($id) => $real->employeePerson($id));
        $repository->method('lockEmployee')->willReturnCallback(function () use ($f, $id): void {
            $f->sql->update('sis_employees', ['active' => 0], ['id' => $id->value]);
        });
        $repository->expects(self::never())->method('add');
        try {
            $this->service($f, repository: $repository)->submit($id, $this->work());
            self::fail('A preceding deactivation was ignored.');
        } catch (DomainException) {
            self::assertSame([], $f->sql->rows('sis_timesheets'));
        }
    }

    public function testTimesheetDirectorySearchIncludesCurrentPersonLabels(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $entry = $service->submit($id, $this->work());
        $records = new SqlWorkspaceRepository($f->sql, $f->gradeCatalog())->records('timesheets', null, 'Alex', null);
        self::assertCount(1, $records['items']);
        self::assertSame($entry->id->value, $records['items'][0]['id']);
        self::assertSame('Alex', $records['items'][0]['given_name']);
        self::assertSame($service->employeeDetails($id)['institutional_id'], $records['items'][0]['institutional_id']);
    }

    public function testSubmissionRechecksOwnershipAfterAcquiringItsLock(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $id = $this->employee($f, $service);
        $person = $service->employeeProfile($id)->person;
        $replacementOwner = $f->user();
        $f->access->staff = false;
        $real = new SqlTimeRepository($f->sql);
        $repository = $this->createMock(TimeRepository::class);
        $repository->method('employeePerson')->willReturnCallback(fn ($id) => $real->employeePerson($id));
        $repository->method('lockEmployee')->willReturnCallback(function () use ($f, $person, $replacementOwner): void {
            $f->sql->update('sis_persons', ['owner_user_id' => $replacementOwner->value], ['id' => $person->id->value]);
        });
        $repository->expects(self::never())->method('add');
        try {
            $this->service($f, repository: $repository)->submit($id, $this->work());
            self::fail('The former owner submitted time after an ownership change.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_timesheets'));
        }
    }
}
