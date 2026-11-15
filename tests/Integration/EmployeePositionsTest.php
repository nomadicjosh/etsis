<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\EmployeePosition;
use Domain\HumanResources\Event\EmployeePositionWasRecorded;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\PositionHistory;
use Domain\HumanResources\ValueObject\EmployeePositionId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class EmployeePositionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    /** @return array{EntityId,JobDefinition,PayGrade} */
    private function setupPosition(SisFixture $s): array
    {
        $employee = $s->timesheetService()->addEmployee($s->student()->personId, 'General profile label');
        $grade = new PayGrade(EntityId::generate(), 'H.1', 'Fictional hourly grade', 'USD', 'hourly', 1000, 5000, true);
        $s->hrLibrary()->save($grade, null);
        $job = new JobDefinition(
            EntityId::generate(),
            'LIB.ASST',
            'Fictional library assistant',
            'USD',
            'hourly',
            true,
            $grade->id,
            2500,
            2250
        );
        $s->hrLibrary()->save($job, null);
        return [$employee, $job, $grade];
    }
    public function testAssignmentsRetainReviewedTermsInOneProtectedJournalAndLaterSetupChangesCannotRelabelThem(): void
    {
        $s = new SisFixture();
        [$employee, $job, $grade] = $this->setupPosition($s);
        $before = $s->sql->rows('sis_history_events');
        $id = EntityId::generate();
        $s->positions()->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, 3000, 1800, 'Private HR agreement');
        $detail = $s->positions()->get($employee, $id);
        self::assertSame($job->title, $detail['job']['title']);
        self::assertSame(3000, $detail['rate_minor']);
        self::assertSame(1800, $detail['weekly_minutes']);
        self::assertSame(2, $detail['currency_digits']);
        self::assertSame(1, $detail['version']);
        self::assertArrayNotHasKey('reason', $detail);
        $s->hrLibrary()->save(new JobDefinition(
            $job->id,
            $job->code,
            'Different future title',
            'USD',
            'hourly',
            true,
            $grade->id,
            3500,
            2400
        ), 0);
        $s->hrLibrary()->save(new PayGrade(
            $grade->id,
            $grade->code,
            'Different grade label',
            'USD',
            'hourly',
            1000,
            5000,
            true
        ), 0);
        self::assertSame($job->title, $s->positions()->get($employee, $id)['job']['title']);
        self::assertSame($grade->title, $s->positions()->get($employee, $id)['grade']['title']);
        self::assertSame($id->value, $s->positions()->assign(
            $employee,
            $id,
            $job->id,
            0,
            0,
            '2026-09-01',
            null,
            3000,
            1800,
            'Private HR agreement'
        )->value);
        self::assertCount(count($before) + 1, $s->sql->rows('sis_history_events'));
        self::assertSame('General profile label', $s->timesheetService()->employeeDetails($employee)['job_title']);
        self::assertCount(1, $s->positions()->page($employee)['items']);
        foreach (['sis_employee_positions', 'sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            $json = json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Private HR agreement', $json);
            self::assertStringNotContainsString($job->title, $json);
        }
        $after = array_column($s->sql->rows('sis_history_events'), null, 'id');
        foreach ($before as $event) {
            self::assertSame($event, $after[$event['id']]);
        }
    }
    public function testChangedTermsCloseThePreviousIntervalAtomicallyAndOldRetriesLeaveLaterEndDatesAlone(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $old = EntityId::generate();
        $next = EntityId::generate();
        $service = $s->positions();
        $service->assign($employee, $old, $job->id, 0, 0, '2026-09-01', null, 2500, 2250, 'Initial agreement');
        $service->assign(
            $employee,
            $next,
            $job->id,
            0,
            0,
            '2027-01-01',
            null,
            2800,
            1800,
            'Updated agreement',
            $old,
            1
        );
        self::assertSame('2026-12-31', $service->get($employee, $old)['valid_through']);
        self::assertSame($old->value, $service->get($employee, $next)['replaces_id']);
        $service->end($employee, $next, 1, '2027-06-30', 'Scheduled completion');
        $events = $s->sql->rows('sis_history_events');
        $service->assign(
            $employee,
            $next,
            $job->id,
            0,
            0,
            '2027-01-01',
            null,
            2800,
            1800,
            'Updated agreement',
            $old,
            1
        );
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame('2027-06-30', $service->get($employee, $next)['valid_through']);
        $service->end($employee, $next, 2, null, 'Cancel scheduled closure');
        $service->end($employee, $next, 1, '2027-06-30', 'Scheduled completion');
        self::assertNull($service->get($employee, $next)['valid_through']);
        $this->expectException(ConcurrentModificationException::class);
        $service->end($employee, $next, 1, '2028-01-01', 'Stale different decision');
    }
    public function testAuditFailureDuringTheNewAssignmentRollsBackBothSidesOfAChange(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $old = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $old,
            $job->id,
            0,
            0,
            '2026-09-01',
            null,
            2500,
            null,
            'Original agreement'
        );
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function ($actor, string $action): void {
            if ($action === 'employee.position.assigned') {
                throw new RuntimeException('Fictional audit failure');
            }
        });
        $tables = ['sis_employee_positions', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        try {
            $s->positions($audit)->assign(
                $employee,
                EntityId::generate(),
                $job->id,
                0,
                0,
                '2027-01-01',
                null,
                2800,
                null,
                'Fictional failed change',
                $old,
                1
            );
            self::fail('A partial position change committed.');
        } catch (RuntimeException) {
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }
    public function testVoidingAndRestoringKeepTheAgreementAndProtectAgainstDelayedRetries(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $service = $s->positions();
        $service->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, 2500, null, 'Unknown workload');
        $service->setVoid($employee, $id, 1, true, 'Incorrect assignment');
        self::assertTrue($service->get($employee, $id)['voided']);
        $service->setVoid($employee, $id, 2, false, 'Confirmed valid assignment');
        $events = $s->sql->rows('sis_history_events');
        $service->setVoid($employee, $id, 1, true, 'Incorrect assignment');
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertFalse($service->get($employee, $id)['voided']);
    }
    public function testOwnersNeedHrAuthorityAndPositionsCannotBeReadThroughAnotherEmployee(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, null, null, 'Unspecified terms');
        $s->access->staff = false;
        try {
            $s->positions()->get($employee, $id);
            self::fail('Ownership granted HR compensation access.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = true;
        $other = $s->timesheetService()->addEmployee($s->student()->personId, 'Another employee');
        $this->expectException(AccessDeniedException::class);
        $s->positions()->get($other, $id);
    }
    public function testAlteredOrMissingProjectionsCannotBecomeHistoricalFacts(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, null, null, 'Unspecified terms');
        $s->sql->update('sis_employee_positions', ['encrypted_position' => 'tampered'], ['id' => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $s->positions()->page($employee);
    }

    public function testStaleReviewedSourcesAndInvalidTermsLeaveNoPositionHistoryOrAudit(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $tables = ['sis_employee_positions', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        foreach (
            [
            [1, 0, '2026-09-01', null, 2500, null, 'Stale job'],
            [0, 1, '2026-09-01', null, 2500, null, 'Stale grade'],
            [0, 0, '2026-02-30', null, 2500, null, 'Invalid date'],
            [0, 0, '2026-09-01', '2026-08-31', 2500, null, 'Reversed interval'],
            [0, 0, '2026-09-01', null, 5001, null, 'Rate exceeds reviewed grade'],
            [0, 0, '2026-09-01', null, 2500, 10081, 'Invalid workload'],
            [0, 0, '2026-09-01', null, 2500, null, '   '],
            ] as $terms
        ) {
            try {
                $s->positions()->assign($employee, EntityId::generate(), $job->id, ...$terms);
                self::fail('Invalid or stale terms were accepted.');
            } catch (ValidationException) {
                self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
            }
        }
    }

    public function testRetiredSetupDoesNotPreventReadingOrClosingAnExistingAgreementButCannotCreateAnother(): void
    {
        $s = new SisFixture();
        [$employee, $job, $grade] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $id,
            $job->id,
            0,
            0,
            '2026-09-01',
            null,
            2500,
            null,
            'Original agreement'
        );
        $s->hrLibrary()->save(new JobDefinition(
            $job->id,
            $job->code,
            $job->title,
            'USD',
            'hourly',
            false,
            $grade->id,
            2500,
            2250
        ), 0);
        $s->hrLibrary()->save(new PayGrade(
            $grade->id,
            $grade->code,
            $grade->title,
            'USD',
            'hourly',
            1000,
            5000,
            false
        ), 0);
        $s->positions()->end($employee, $id, 1, '2027-01-31', 'Close retained agreement');
        self::assertSame('2027-01-31', $s->positions()->get($employee, $id)['valid_through']);
        self::assertTrue($s->positions()->get($employee, $id)['grade']['active']);
        $this->expectException(ValidationException::class);
        $s->positions()->assign(
            $employee,
            EntityId::generate(),
            $job->id,
            1,
            1,
            '2027-02-01',
            null,
            2500,
            null,
            'Retired job cannot be newly accepted'
        );
    }

    public function testAuthorityRevokedBeforeCommitRollsBackTheAssignmentAndItsJournal(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
        });
        $tables = ['sis_employee_positions', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        try {
            $s->positions($audit)->assign(
                $employee,
                EntityId::generate(),
                $job->id,
                0,
                0,
                '2026-09-01',
                null,
                2500,
                null,
                'Authority changes during write'
            );
            self::fail('Revoked authority committed compensation.');
        } catch (AccessDeniedException) {
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }

    public function testAssignmentListingIsPagedWithoutReplayingEachPositionAndChecksItsIndexedHead(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        for ($i = 0; $i < 22; $i++) {
            $s->positions()->assign(
                $employee,
                EntityId::generate(),
                $job->id,
                0,
                0,
                '2026-09-01',
                null,
                null,
                null,
                'Concurrent fictional appointment ' . $i
            );
        }
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $first = $s->positions(history: $history)->page($employee);
        self::assertCount(20, $first['items']);
        $second = $s->positions(history: $history)->page($employee, new EntityId($first['next_after']));
        self::assertCount(2, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
        $s->sql->update('sis_history_streams', ['version' => 2], ['aggregate_id' => $first['items'][0]['id']]);
        $this->expectException(CorruptHistoryException::class);
        $s->positions()->page($employee);
    }

    public function testADeletedProjectionCannotBeReplacedByASecondInitialAssignment(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign(
            $employee,
            $id,
            $job->id,
            0,
            0,
            '2026-09-01',
            null,
            2500,
            null,
            'Original agreement'
        );
        $s->sql->execute('DELETE FROM sis_employee_positions WHERE id = ?', [$id->value]);
        $before = $s->sql->rows('sis_history_events');
        try {
            $s->positions()->assign(
                $employee,
                $id,
                $job->id,
                0,
                0,
                '2026-09-01',
                null,
                2500,
                null,
                'Original agreement'
            );
            self::fail('Missing tracked projection was silently recreated.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        $this->expectException(CorruptHistoryException::class);
        $s->positions()->get($employee, $id);
    }

    public function testSelectedReplayRefusesOversizedAndNonAdvancingHistory(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, null, null, 'Original agreement');
        foreach ([new EventPage([], null, 2001), new EventPage([], 'stalled', 1)] as $page) {
            $history = $this->createMock(HistoricalEventStore::class);
            $history->expects(self::once())->method('page')->willReturn($page);
            try {
                $s->positions(history: $history)->get($employee, $id);
                self::fail('Incomplete or oversized position history returned a private agreement.');
            } catch (HistoryReadLimitException | CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRetainedCurrencyPrecisionAndExplicitZeroDoNotDependOnTodaysReferenceLibrary(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $snapshot = $job->fields();
        $snapshot['currency'] = 'ZZZ';
        $snapshot['pay_grade_id'] = null;
        $position = new EmployeePosition(
            EntityId::generate(),
            $employee,
            $snapshot,
            0,
            null,
            null,
            '2026-09-01',
            null,
            0,
            0,
            1,
            3
        );
        $facts = $position->facts();
        ksort($facts);
        ksort($facts['job']);
        $restored = EmployeePosition::restore($facts);
        self::assertSame($position->facts(), $restored->facts());
        self::assertSame(0, $restored->rateMinor);
        self::assertSame(0, $restored->weeklyMinutes);
        self::assertSame(3, $restored->currencyDigits);
    }

    public function testAnotherSuperPersonsAgreementsRequireSuperAuthorityForReadsAndWrites(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $id = EntityId::generate();
        $s->positions()->assign($employee, $id, $job->id, 0, 0, '2026-09-01', null, null, null, 'Original agreement');
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $s->access->actor,
            $roles->roleNamed('super')->id,
            true
        );
        $s->access->actor = $s->user();
        $before = $s->sql->rows('sis_history_events');
        foreach (['get', 'page', 'end'] as $action) {
            try {
                match ($action) {
                    'get' => $s->positions()->get($employee, $id),
                    'page' => $s->positions()->page($employee),
                    'end' => $s->positions()->end($employee, $id, 1, '2027-01-31', 'Unauthorized closure'),
                };
                self::fail('Non-super HR authority exposed another super persons agreement.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $s->sql->rows('sis_history_events'));
            }
        }
    }

    public function testEndDateReplayCannotSilentlyRewriteTheAgreedRate(): void
    {
        $s = new SisFixture();
        [$employee, $job] = $this->setupPosition($s);
        $snapshot = $job->fields();
        $snapshot['pay_grade_id'] = null;
        $id = EntityId::generate();
        $position = new EmployeePosition(
            $id,
            $employee,
            $snapshot,
            0,
            null,
            null,
            '2026-09-01',
            null,
            2500,
            null,
            1,
            2
        );
        $root = PositionHistory::root(new EmployeePositionId($id->value));
        $operation = EntityId::generate();
        $root->applyRecord(EmployeePositionWasRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => null,
            'action' => 'assigned', 'facts' => $position->facts(), 'intent' => str_repeat('a', 64),
        ])->withPlayhead(1));
        $facts = $position->changeEnd('2027-01-31')->facts();
        $facts['rate_minor'] = 3000;
        $this->expectException(CorruptHistoryException::class);
        $root->applyRecord(EmployeePositionWasRecorded::occur($root->aggregateId(), [
            'operation_id' => EntityId::generate()->value, 'previous_operation_id' => $operation->value,
            'action' => 'end_changed', 'facts' => $facts, 'intent' => str_repeat('b', 64),
        ])->withPlayhead(2));
    }
}
