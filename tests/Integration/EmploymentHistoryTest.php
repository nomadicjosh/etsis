<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use RuntimeException;
use Domain\Scheduling\TimeSlot;
use Domain\Audit\Repository\AuditTrail;
use Domain\Access\Enum\GrantKind;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\HumanResources\Service\EmploymentLedger;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Domain\HumanResources\Employee;
use Domain\HumanResources\ValueObject\EmploymentProfileId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class EmploymentHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function history(SisFixture $s): SqlHistoricalEventStore
    {
        return new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
    }
    public function testNewEmploymentAndReasonedEditsRetainProtectedFactsWithoutChangingTheParentOrOtherRoles(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $service = $s->timesheetService();
        $persons = $s->sql->rows('sis_persons');
        $users = $s->sql->rows('users');
        $id = $service->addEmployee($student->personId, 'Fictional assistant');
        $before = $service->employeeDetails($id);
        self::assertFalse($before['earlier_history_unknown']);
        $service->editEmployee($id, 'Fictional librarian', false, $before['revision'], 'Fictional staffing decision');
        self::assertFalse($service->employeeDetails($id)['active']);
        self::assertTrue($s->admissions->student($student->id)->active);
        self::assertSame($persons, $s->sql->rows('sis_persons'));
        self::assertSame($users, $s->sql->rows('users'));
        $events = $this->history($s)->page(
            new StreamReference(new EmploymentProfileId($id->value), 'employment_profile')
        )->events;
        self::assertCount(2, $events);
        self::assertSame('created', $events[0]->event->payload()['provenance']);
        self::assertSame('Fictional assistant', $events[0]->event->payload()['facts']['job_title']);
        self::assertSame('Fictional librarian', $events[1]->event->payload()['facts']['job_title']);
        self::assertSame('Fictional staffing decision', $events[1]->context->reason);
        self::assertSame($s->access->actor->value, $events[1]->context->actor->value);
        self::assertSame($s->clock->now()->format('c'), $events[1]->context->effectiveAt->format('c'));
        foreach (['sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            $json = json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR);
            foreach (['Fictional assistant', 'Fictional librarian', 'Fictional staffing decision'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
        }
        $beforeRetry = $s->sql->rows('sis_history_events');
        $service->editEmployee($id, 'Fictional librarian', false, $before['revision'], 'Fictional staffing decision');
        self::assertSame($beforeRetry, $s->sql->rows('sis_history_events'));
        self::assertSame($id->value, $service->addEmployee($student->personId, 'Fictional assistant')->value);
        self::assertFalse($service->employeeDetails($id)['active']);
    }
    public function testOldRowsAreNotBackfilledOnReadAndGainAnHonestObservationOnlyAtAnActualEdit(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $id = EntityId::generate();
        new SqlTimeRepository($s->sql, $s->dropCipher)->addEmployee($id, $student->personId, 'Earlier title');
        $service = $s->timesheetService();
        $row = $service->employeeDetails($id);
        self::assertTrue($row['earlier_history_unknown']);
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $service->editEmployee($id, 'Earlier title', true, $row['revision'], 'Fictional unchanged review');
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $service->editEmployee($id, 'New title', true, $row['revision'], 'Fictional current edit');
        $events = $this->history($s)->page(
            new StreamReference(new EmploymentProfileId($id->value), 'employment_profile')
        )->events;
        self::assertCount(2, $events);
        self::assertSame('observed', $events[0]->event->payload()['provenance']);
        self::assertSame('Earlier title', $events[0]->event->payload()['facts']['job_title']);
        self::assertSame($s->clock->now()->format('c'), $events[0]->context->effectiveAt->format('c'));
        self::assertTrue($service->employeeDetails($id)['earlier_history_unknown']);
    }
    public function testLateRetriesCannotUndoLaterChangesAndChangedBackValuesDoNotMakeAnOldRevisionCurrent(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Title A');
        $old = $service->employeeDetails($id);
        $service->editEmployee($id, 'Title B', false, $old['revision'], 'Fictional first change');
        $second = $service->employeeDetails($id);
        $service->editEmployee($id, 'Title A', true, $second['revision'], 'Fictional restoration');
        $events = $s->sql->rows('sis_history_events');
        $service->editEmployee($id, 'Title B', false, $old['revision'], 'Fictional first change');
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertTrue($service->employeeDetails($id)['active']);
        self::assertNotSame($old['revision'], $service->employeeDetails($id)['revision']);
        $this->expectException(ConcurrentModificationException::class);
        $service->editEmployee($id, 'Title C', true, $old['revision'], 'Fictional stale new intent');
    }
    public function testProjectionDriftCannotAuthorizeTimeSubmission(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
        $service->editEmployee(
            $id,
            'Fictional assistant',
            false,
            $service->employeeDetails($id)['revision'],
            'Fictional pause'
        );
        $s->sql->update('sis_employees', ['active' => 1], ['id' => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $start = new DateTimeImmutable('2026-08-11T09:00:00Z');
        $service->submit($id, new TimeSlot($start, $start->modify('+1 hour')));
    }
    public function testRequiredAuditFailureOrRevokedAuthorityRollsBackTheProfileEventsAndReceipt(): void
    {
        foreach (['audit', 'actor', 'permission'] as $case) {
            $s = new SisFixture();
            $service = $s->timesheetService();
            $id = $service->addEmployee($s->student()->personId, 'Original title');
            $old = $service->employeeDetails($id);
            $tables = ['sis_employees', 'sis_history_events',
                'sis_history_streams', 'sis_history_operations', 'sis_audit'];
            $before = array_map(fn($t) => $s->sql->rows($t), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $case): void {
                if ($case === 'audit') {
                    throw new RuntimeException('Fictional audit failure');
                }
                if ($case === 'actor') {
                    $s->access->actor = EntityId::generate();
                } else {
                    $s->access->staff = false;
                    $s->access->grants = [];
                }
            });
            try {
                $s->timesheetService($audit)->editEmployee(
                    $id,
                    'Updated',
                    false,
                    $old['revision'],
                    'Fictional decision'
                );
                self::fail('An incomplete employment edit committed.');
            } catch (RuntimeException) {
                self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
            }
        }
    }
    public function testNonSuperHrStaffCannotChangeASuperPersonsEmployment(): void
    {
        $s = new SisFixture();
        $student = $s->student($s->user());
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(
            GrantKind::UserRole,
            $s->admissions->person($student->personId)->ownerUserId,
            $roles->roleNamed('super')->id,
            true
        );
        $this->expectException(AccessDeniedException::class);
        $s->timesheetService()->addEmployee($student->personId, 'Protected title');
    }
    public function testCreationRollsBackWhenAuditFailsAndADifferentActorCannotAdoptAnEarlierReceipt(): void
    {
        $s = new SisFixture();
        $person = $s->student()->personId;
        $tables = ['sis_employees', 'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional required audit failure'));
        try {
            $s->timesheetService($audit)->addEmployee($person, 'Fictional assistant');
            self::fail('An unaudited employment creation committed.');
        } catch (RuntimeException) {
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
        $s->timesheetService()->addEmployee($person, 'Fictional assistant');
        $accepted = array_map(fn($t) => $s->sql->rows($t), $tables);
        $s->access->actor = $s->user();
        try {
            $s->timesheetService()->addEmployee($person, 'Fictional assistant');
            self::fail('Another actor adopted the original creation receipt.');
        } catch (ValidationException $error) {
            self::assertSame('person_id', $error->errors[0]['field']);
            self::assertSame($accepted, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }
    public function testReasonIsRequiredAndAnOversizedHistoryIsNotPartiallyReplayed(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
        try {
            $service->editEmployee($id, 'New', true, $service->employeeDetails($id)['revision'], ' ');
            self::fail('An unexplained profile edit was accepted.');
        } catch (ValidationException $e) {
            self::assertSame('reason', $e->errors[0]['field']);
        }
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::once())->method('page')->willReturn(new EventPage([], null, 2001));
        $ledger = new EmploymentLedger($history, $s->clock);
        $this->expectException(HistoryReadLimitException::class);
        $ledger->inspect(new Employee(
            $id,
            new EntityId($service->employeeDetails($id)['person_id']),
            'Fictional assistant',
            true
        ));
    }
}
