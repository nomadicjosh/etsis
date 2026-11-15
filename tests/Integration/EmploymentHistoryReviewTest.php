<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\ActorDirectory;
use Domain\HumanResources\Service\EmploymentLedger;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class EmploymentHistoryReviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    public function testApprovedHistoryFactsExcludeReasonsOperationsAndCredentialsAndTheReadWritesNothing(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional assistant');
        $service->editEmployee(
            $id,
            'Fictional librarian',
            false,
            $service->employeeDetails($id)['revision'],
            'Private employment reason'
        );
        $s->sql->update(
            'users',
            ['first_name' => 'Fictional', 'last_name' => 'Reviewer'],
            ['user_id' => $s->access->actor->value]
        );
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_employees',
            'sis_persons', 'users', 'sis_timesheets', 'sis_audit'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $page = $service->historyPage($id);
        self::assertFalse($page['earlier_history_unknown']);
        self::assertSame(2, $page['through_version']);
        self::assertNull($page['next_after']);
        self::assertSame(['Job title', 'Active status'], $page['items'][0]['fields']);
        self::assertSame('Fictional librarian', $page['items'][0]['job_title']);
        self::assertFalse($page['items'][0]['active']);
        self::assertSame('Fictional assistant', $page['items'][1]['job_title']);
        self::assertSame('Employment created', $page['items'][1]['action']);
        self::assertSame('Fictional Reviewer', $page['items'][0]['recorded_by']);
        self::assertSame(
            $s->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            $page['items'][0]['effective_at']
        );
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        foreach (
            ['Private employment reason', 'reason', 'operation_id', 'actor_id', 'intent', 'password',
            'person_id', 'payload', $s->access->actor->value] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
    }

    public function testOlderUntrackedEmploymentAndCurrentObservationsDoNotInventAPastCreation(): void
    {
        $s = new SisFixture();
        $id = EntityId::generate();
        new SqlTimeRepository($s->sql, $s->dropCipher)->addEmployee($id, $s->student()->personId, 'Earlier title');
        $service = $s->timesheetService();
        self::assertSame(['items' => [], 'through_version' => 0, 'next_after' => null,
            'earlier_history_unknown' => true], $service->historyPage($id));
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $service->editEmployee(
            $id,
            'Updated title',
            true,
            $service->employeeDetails($id)['revision'],
            'Private decision'
        );
        $page = $service->historyPage($id);
        self::assertTrue($page['earlier_history_unknown']);
        self::assertSame('Existing details first recorded', $page['items'][1]['action']);
        self::assertSame('Earlier title', $page['items'][1]['job_title']);
        self::assertSame([], $page['items'][1]['fields']);
        self::assertTrue($page['items'][1]['earlier_details_unknown']);
        self::assertSame(['Job title'], $page['items'][0]['fields']);
        self::assertSame([], $service->historyPage($id, 0, 0)['items']);
    }

    public function testPagingKeepsItsOriginalBoundaryAcrossLaterEditsAndResolvesOnlySelectedRecorders(): void
    {
        $s = new SisFixture();
        $service = $s->timesheetService();
        $id = $service->addEmployee($s->student()->personId, 'Fictional title 0');
        for ($n = 1; $n <= 25; ++$n) {
            $service->editEmployee(
                $id,
                'Fictional title ' . $n,
                true,
                $service->employeeDetails($id)['revision'],
                'Private reason ' . $n
            );
        }
        $actors = $this->createMock(ActorDirectory::class);
        $actors->expects(self::exactly(3))->method('names')->willReturnCallback(static function (array $ids): array {
            self::assertLessThanOrEqual(20, count($ids));
            return [];
        });
        $reader = $s->timesheetService(actors: $actors);
        $first = $reader->historyPage($id);
        self::assertSame(range(26, 7), array_column($first['items'], 'sequence'));
        self::assertSame('Unavailable account', $first['items'][0]['recorded_by']);
        $service->editEmployee(
            $id,
            'Fictional later title',
            true,
            $service->employeeDetails($id)['revision'],
            'Private later reason'
        );
        $second = $reader->historyPage($id, $first['next_after'], $first['through_version']);
        self::assertSame(26, $second['through_version']);
        self::assertSame(range(6, 1), array_column($second['items'], 'sequence'));
        self::assertNull($second['next_after']);
        self::assertSame(27, $reader->historyPage($id)['items'][0]['sequence']);
    }

    public function testOwnerOrEitherSinglePermissionCannotGrantTheOtherHistoryPermission(): void
    {
        $s = new SisFixture();
        $id = $s->timesheetService()->addEmployee($s->student()->personId, 'Fictional assistant');
        $s->access->staff = false;
        foreach ([[], ['sis:hr:manage'], ['sis:audit:read']] as $grants) {
            $s->access->grants = $grants;
            try {
                $s->timesheetService()->historyPage($id);
                self::fail('Ownership or one permission granted protected HR history.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $s->access->grants = ['sis:hr:manage', 'sis:audit:read'];
        self::assertCount(1, $s->timesheetService()->historyPage($id)['items']);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $s->access->actor,
            $roles->roleNamed('super')->id,
            true
        );
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->timesheetService()->historyPage($id);
    }

    public function testReadsRecheckAuthorityAndProjectionAfterResolvingCurrentRecorderLabels(): void
    {
        foreach (['actor', 'permission', 'projection'] as $case) {
            $s = new SisFixture();
            $id = $s->timesheetService()->addEmployee($s->student($s->user())->personId, 'Fictional assistant');
            $actors = $this->createStub(ActorDirectory::class);
            $actors->method('names')->willReturnCallback(static function () use ($s, $id, $case): array {
                if ($case === 'actor') {
                    $s->access->actor = EntityId::generate();
                } elseif ($case === 'permission') {
                    $s->access->staff = false;
                } else {
                    $s->sql->update('sis_employees', ['job_title' => 'Concurrent title'], ['id' => $id->value]);
                }
                return [];
            });
            try {
                $s->timesheetService(actors: $actors)->historyPage($id);
                self::fail('A changed authority or record returned history.');
            } catch (AccessDeniedException | ConcurrentModificationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testInvalidPageBoundsAndTamperedTrackedProjectionAreRejected(): void
    {
        $s = new SisFixture();
        $id = $s->timesheetService()->addEmployee($s->student()->personId, 'Fictional assistant');
        foreach ([[-1, null], [1, 1], [20, null], [20, 1], [0, -1], [0, 2], [0, 2001]] as [$after, $through]) {
            try {
                $s->timesheetService()->historyPage($id, $after, $through);
                self::fail('An invalid history window returned a partial page.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $s->sql->update('sis_employees', ['job_title' => 'Tampered title'], ['id' => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $s->timesheetService()->historyPage($id);
    }

    public function testOversizedOrNonAdvancingHistoryStopsWithoutAnUnboundedRead(): void
    {
        $s = new SisFixture();
        $id = $s->timesheetService()->addEmployee($s->student()->personId, 'Fictional assistant');
        $employee = new SqlTimeRepository($s->sql, $s->dropCipher)->employee($id);
        foreach ([new EventPage([], null, 2001), new EventPage([], 'non-advancing', 1)] as $page) {
            $history = $this->createMock(HistoricalEventStore::class);
            $history->expects(self::once())->method('page')->willReturn($page);
            try {
                new EmploymentLedger($history, $s->clock)->review($employee);
                self::fail('Unbounded or incomplete history was accepted.');
            } catch (HistoryReadLimitException | CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
}
