<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AdvisingAppointment;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Tests\Support\AdvisingAvailabilityFixture;

final class AdvisingAvailabilityTest extends TestCase
{
    private function fixture(): AdvisingAvailabilityFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        return new AdvisingAvailabilityFixture();
    }

    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
            self::fail('Expected a rejected private availability operation.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    private function book(
        AdvisingAvailabilityFixture $f,
        EntityId $window,
        ?EntityId $id = null,
        string $start = '2026-08-11T10:00',
    ): array {
        $a = $f->appointments;
        return $a->service()->book($a->student, $a->pursuit, $id ?? EntityId::generate(), $window, 0, $start);
    }

    public function testOwnerFindsAndBooksAnActualSlotAndClosurePreservesOriginalSourceReceiptsAndCalendar(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $windowId = EntityId::generate();
        $created = $f->offer($windowId);
        $history = \EventStoreFixture::historyDigest($s->sql);
        $s->access->actor = $a->owner;
        $s->access->staff = false;
        $choices = $a->service()->bookingChoices($a->student, $a->pursuit);
        self::assertSame([$a->host->value], array_column($choices['hosts'], 'id'));
        self::assertStringNotContainsString('user_id', json_encode($choices));
        $windows = $a->service()->bookingChoices($a->student, $a->pursuit, $a->host, '2026-08-11');
        self::assertSame([$windowId->value], array_column($windows['windows'], 'id'));
        $slots = $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $windowId);
        self::assertCount(4, $slots['slots']);
        self::assertStringNotContainsString('actor_id', json_encode($slots));
        $id = new EntityId($slots['new_id']);
        $receipt = $this->book($f, $windowId, $id);
        self::assertSame($receipt, $this->book($f, $windowId, $id));
        $view = $a->service()->listing($a->student, $a->pursuit, 'owner', selected: $id);
        self::assertSame('Fictional office meeting instructions.', $view['items'][0]['message']);
        self::assertStringNotContainsString('host_user_id', json_encode($view));
        $before = $a->records->find($a->student, $a->pursuit, $id)->facts();
        self::assertSame($windowId->value, $before['booking']['window_id']);
        self::assertSame($before, AdvisingAppointment::restore($before)->facts());
        self::assertCount(1, $a->service()->calendar('owner', null, null, '2026-08-11')['items']);
        $s->access->actor = $a->staff;
        $s->access->staff = true;
        $closed = $f->service()->close($a->host, 'staff', $windowId, 0, 'Fictional office closure.');
        self::assertSame($closed, $f->service()->close($a->host, 'staff', $windowId, 0, 'Fictional office closure.'));
        self::assertSame($created, $f->offer($windowId));
        self::assertSame($before, $a->records->find($a->student, $a->pursuit, $id)->facts());
        $s->access->actor = $a->owner;
        self::assertSame($receipt, $this->book($f, $windowId, $id));
        $s->sql->update('sis_students', ['active' => 0], ['id' => $a->student->value]);
        self::assertSame($receipt, $this->book($f, $windowId, $id));
        self::assertSame($history, \EventStoreFixture::historyDigest($s->sql));
        self::assertSame([], $s->sql->rows('sis_advising_checkins'));
        self::assertSame([], $s->sql->rows('sis_advising_updates'));
    }

    public function testAdvisorManagesOwnAvailabilityWithoutStudentReadOrAssignmentManagementDuties(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $s->access->actor = $a->advisor;
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION];
        self::assertTrue($f->service()->canManage());
        self::assertSame($a->host->value, $f->service()->mine()->value);
        $id = new EntityId($f->offer(channel: 'advisor')['id']);
        self::assertCount(1, $f->service()->listing($a->host, 'advisor')['items']);
        $this->rejects(fn() => $f->service()->listing($a->host, 'staff'), AccessDeniedException::class);
        $this->rejects(fn() => $f->service()->candidates(), AccessDeniedException::class);
        $other = $s->legacyPerson($s->user(), 'Other', 'Person');
        $this->rejects(fn() => $f->service()->listing($other->id, 'advisor'), AccessDeniedException::class);
        self::assertSame('closed', $f->service()->close($a->host, 'advisor', $id, 0, 'Fictional closure.')['status']);
        $s->access->actor = $a->owner;
        self::assertFalse($f->service()->canManage());
    }

    public function testASecondStudentCannotBookTheSameSlotOrSeeTheFirstStudentsDetails(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = new EntityId($f->offer()['id']);
        $otherOwner = $s->user();
        $other = $s->student($otherOwner);
        $otherPursuit = new EntityId($s->sql->rows(
            'sis_student_programs',
            ['student_id' => $other->id->value],
            1
        )[0]['id']);
        $advisor = $s->advisors();
        $view = $advisor->view($other->id, $otherPursuit);
        $review = $advisor->review($other->id, $otherPursuit, [[
            'id' => EntityId::generate()->value, 'advisor_person_id' => $a->host->value,
            'role' => 'primary', 'starts_on' => '2026-01-01', 'ends_on' => null,
        ]], $view['revision'], 'Fictional assignment.');
        $advisor->commit(
            $other->id,
            $otherPursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        $s->access->actor = $a->owner;
        $this->book($f, $id);
        $s->access->actor = $otherOwner;
        $available = $a->service()->bookingChoices($other->id, $otherPursuit, windowId: $id);
        self::assertCount(3, $available['slots']);
        self::assertNotContains('2026-08-11T10:00', array_column($available['slots'], 'start'));
        self::assertStringNotContainsString($a->student->value, json_encode($available));
        $this->rejects(fn() => $a->service()->book(
            $other->id,
            $otherPursuit,
            EntityId::generate(),
            $id,
            0,
            '2026-08-11T10:00'
        ), ConcurrentModificationException::class);
        self::assertSame('scheduled', $a->service()->book(
            $other->id,
            $otherPursuit,
            EntityId::generate(),
            $id,
            0,
            '2026-08-11T10:30'
        )['status']);
    }

    public function testSourceAndCurrentOwnerScopeCannotBeBypassedWithAnArbitraryTimeOrPrivilege(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = new EntityId($f->offer()['id']);
        $this->rejects(fn() => $this->book($f, $id), AccessDeniedException::class);
        $s->access->actor = $a->owner;
        $this->rejects(fn() => $this->book($f, $id, start: '2026-08-11T10:05'), ConcurrentModificationException::class);
        $this->rejects(fn() => $this->book($f, $id, start: '2026-08-11T12:00'), ConcurrentModificationException::class);
        $this->rejects(fn() => $a->service()->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $id,
            1,
            '2026-08-11T10:00'
        ), ConcurrentModificationException::class);
        $this->rejects(fn() => $a->schedule(channel: 'owner'), AccessDeniedException::class);
        $otherHost = $s->legacyPerson($s->user(), 'Foreign', 'Advisor')->id;
        $this->rejects(fn() => $a->service()->bookingChoices(
            $a->student,
            $a->pursuit,
            $otherHost,
            '2026-08-11'
        ), AccessDeniedException::class);
    }

    public function testChangedAssignmentsAndInactivePrimaryPursuitsStopNewBooking(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $id = new EntityId($f->offer()['id']);
        $f->appointments->assign(end: '2026-08-10');
        $a->sis->access->actor = $a->owner;
        $this->rejects(fn() => $this->book($f, $id), AccessDeniedException::class);
        $a->sis->access->actor = $a->staff;
        $a->assign();
        $a->sis->access->actor = $a->owner;
        $a->sis->sql->update('sis_students', ['active' => 0], ['id' => $a->student->value]);
        $this->rejects(fn() => $this->book($f, $id), AccessDeniedException::class);
        $this->rejects(fn() => $a->service()->bookingChoices($a->student, $a->pursuit), AccessDeniedException::class);
    }

    public function testClosingAndReofferingAvailabilityDoesNotFreeAConfirmedAppointment(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $first = new EntityId($f->offer()['id']);
        $a->sis->access->actor = $a->owner;
        $receipt = $this->book($f, $first);
        $a->sis->access->actor = $a->staff;
        $f->service()->close($a->host, 'staff', $first, 0, 'Fictional replacement.');
        $second = new EntityId($f->offer()['id']);
        $a->sis->access->actor = $a->owner;
        self::assertCount(3, $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $second)['slots']);
        self::assertSame($receipt, $this->book($f, $first, new EntityId($receipt['id'])));
    }

    #[DataProvider('bookingFailures')]
    public function testFinalSourceAuthorityAndAuditFailuresRollBackBookingAndCoupledChanges(string $failure): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = new EntityId($f->offer()['id']);
        $before = $f->records->find($id)->facts();
        $s->access->actor = $a->owner;
        $history = \EventStoreFixture::historyDigest($s->sql);
        $audit = $a->audit(function () use ($f, $a, $s, $id, $failure): void {
            if ($failure === 'window') {
                $item = $f->records->find($id);
                $item->close($a->staff, 0, 'Fictional competing closure.', '2026-08-10T17:00:00Z');
                $f->records->save($item, 0);
            } elseif ($failure === 'audit') {
                throw new \RuntimeException('Fictional audit failure.');
            } elseif ($failure === 'owner') {
                $s->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $s->user()->value],
                    ['id' => $s->admissions->student($a->student)->personId->value]
                );
            } elseif ($failure === 'host') {
                $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value], ['id' => $a->host->value]);
            } else {
                $s->sql->update('sis_students', ['active' => 0], ['id' => $a->student->value]);
            }
        });
        $this->rejects(fn() => $a->service($audit)->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $id,
            0,
            '2026-08-11T10:00'
        ), match ($failure) {
                'audit' => \RuntimeException::class, 'owner' => AccessDeniedException::class,
                default => ConcurrentModificationException::class,
        });
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
        self::assertSame($before, $f->records->find($id)->facts());
        self::assertSame($history, \EventStoreFixture::historyDigest($s->sql));
    }

    public static function bookingFailures(): array
    {
        return [['window'], ['audit'], ['owner'], ['host'], ['student']];
    }

    public function testExpiredWindowsOriginalRetriesPagingAndClosureRemainExplicit(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        for ($i = 1; $i <= 11; $i++) {
            $f->offer(date: sprintf('2026-09-%02d', $i));
        }
        $page = $f->service()->listing($a->host, 'staff');
        self::assertCount(10, $page['items']);
        self::assertCount(1, $f->service()->listing(
            $a->host,
            'staff',
            after: new EntityId($page['next_after'])
        )['items']);
        $originalId = EntityId::generate();
        $receipt = $f->offer($originalId);
        $a->sis->clock->time = new DateTimeImmutable('2026-08-12T10:00:00-07:00');
        self::assertSame($receipt, $f->offer($originalId));
        self::assertCount(1, $f->service()->listing($a->host, 'staff', true)['items']);
        $a->sis->access->actor = $a->owner;
        $this->rejects(fn() => $this->book($f, $originalId), ValidationException::class);
    }

    public function testCiphertextIndexDamageAndOwnerReplacementCannotTransferAvailability(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = new EntityId($f->offer()['id']);
        $row = $s->sql->rows('sis_advising_availability', ['id' => $id->value], 1)[0];
        $s->sql->update('sis_advising_availability', ['version' => 1], ['id' => $id->value]);
        $this->rejects(fn() => $f->records->find($id), CorruptHistoryException::class);
        $s->sql->update('sis_advising_availability', $row, ['id' => $id->value]);
        $new = $s->user();
        $s->sql->update('sis_persons', ['owner_user_id' => $new->value], ['id' => $a->host->value]);
        $s->access->actor = $new;
        self::assertSame([], $f->service()->listing($a->host, 'advisor')['items']);
        $this->rejects(
            fn() => $f->service()->close($a->host, 'advisor', $id, 0, 'Wrong account.'),
            AccessDeniedException::class
        );
        $s->access->actor = $a->owner;
        $this->rejects(
            fn() => $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $id),
            AccessDeniedException::class
        );
        $s->access->actor = $a->staff;
        self::assertSame('closed', $f->service()->close($a->host, 'staff', $id, 0, 'Account link changed.')['status']);
    }

    public function testOfficeDirectoryRetainsRecordedHostsWithoutMakingThemEligibleAdvisors(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = new EntityId($f->offer()['id']);
        $s->sql->update('sis_employees', ['active' => 0], ['person_id' => $a->host->value]);
        $s->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $a->advisor->value]);
        $directory = new \Infrastructure\Persistence\Repository\SqlAdvisorRepository($s->sql);
        self::assertSame([], $directory->candidates('Fictional')['items']);
        self::assertSame([$a->host->value], array_column($f->service()->candidates('Fictional')['items'], 'id'));
        self::assertFalse($f->service()->listing($a->host, 'staff')['can_create']);
        $s->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $a->host->value]);
        self::assertSame([$a->host->value], array_column($f->service()->candidates('Advisor')['items'], 'id'));
        self::assertSame([], $f->service()->candidates('%')['items']);
        self::assertSame([], $f->service()->candidates(after: $a->host)['items']);
        self::assertSame('closed', $f->service()->close($a->host, 'staff', $id, 0, 'Fictional duty ended.')['status']);
        self::assertSame([$a->host->value], array_column($f->service()->candidates()['items'], 'id'));
    }

    public function testCreateAndClosureRecheckDutyAndPreserveDraftFactsOnFinalFailure(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        $audit = $a->audit(function () use ($s): void {
            $s->access->grants = [];
        });
        $this->rejects(fn() => $f->service($audit)->create(
            $a->host,
            'staff',
            EntityId::generate(),
            '2026-08-11T10:00',
            '2026-08-11T12:00',
            '-07:00',
            30,
            'Fictional instructions.'
        ), AccessDeniedException::class);
        self::assertSame([], $s->sql->rows('sis_advising_availability'));
        $s->access->staff = true;
        $id = new EntityId($f->offer()['id']);
        $before = $f->records->find($id)->facts();
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        $this->rejects(
            fn() => $f->service($audit)->close($a->host, 'staff', $id, 0, 'Fictional closure.'),
            AccessDeniedException::class
        );
        self::assertSame($before, $f->records->find($id)->facts());
    }

    public function testOverlappingWindowsFailButAdjacentWindowsAndExistingAppointmentsAreAllowed(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $a->schedule();
        $f->offer();
        $this->rejects(fn() => $f->offer(), ConcurrentModificationException::class);
        self::assertSame('open', $f->service()->create(
            $a->host,
            'staff',
            EntityId::generate(),
            '2026-08-11T12:00',
            '2026-08-11T13:00',
            '-07:00',
            30,
            'Fictional adjacent availability.'
        )['status']);
    }

    public function testTwentyDailyWindowsAndOneHundredUpcomingWindowsAreExplicitBounds(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        for ($i = 0; $i < 20; $i++) {
            $start = new DateTimeImmutable('2026-08-11T10:00:00-07:00')->modify('+' . ($i * 5) . ' minutes');
            $item = AdvisingAvailability::create(
                EntityId::generate(),
                $a->host,
                $a->advisor,
                $a->staff,
                $a->time($start->format('Y-m-d\TH:i'), $start->modify('+5 minutes')->format('Y-m-d\TH:i')),
                5,
                'Fictional small window.',
                '2026-08-10T17:00:00Z'
            );
            $f->records->save($item, null);
        }
        $this->rejects(fn() => $f->service()->create(
            $a->host,
            'staff',
            EntityId::generate(),
            '2026-08-11T13:00',
            '2026-08-11T14:00',
            '-07:00',
            30,
            'Too many on one date.'
        ), ConcurrentModificationException::class);
        for ($i = 0; $i < 80; $i++) {
            $day = new DateTimeImmutable('2026-09-01')->modify('+' . $i . ' days')->format('Y-m-d');
            $f->offer(date: $day);
        }
        $this->rejects(fn() => $f->offer(date: '2026-12-01'), ConcurrentModificationException::class);
        self::assertSame(100, $f->records->activeCount($a->host, '2026-08-10T17:00:00Z'));
    }

    public function testACompetingWindowInsertedDuringFinalAuditRollsBackBothOffers(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $audit = $a->audit(function () use ($f, $a): void {
            $item = AdvisingAvailability::create(
                EntityId::generate(),
                $a->host,
                $a->advisor,
                $a->staff,
                $a->time('2026-08-11T13:00', '2026-08-11T14:00'),
                30,
                'Fictional competing window.',
                '2026-08-10T17:00:00Z'
            );
            $f->records->save($item, null);
        });
        $this->rejects(fn() => $f->service($audit)->create(
            $a->host,
            'staff',
            EntityId::generate(),
            '2026-08-11T13:00',
            '2026-08-11T15:00',
            '-07:00',
            30,
            'Fictional first offer.'
        ), ConcurrentModificationException::class);
        self::assertSame([], $a->sis->sql->rows('sis_advising_availability'));
    }

    public function testPersonalCancellationReleasesTheTimeWithoutDeletingTheOriginalBooking(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $id = new EntityId($f->offer()['id']);
        $a->sis->access->actor = $a->owner;
        $receipt = $this->book($f, $id);
        $appointment = new EntityId($receipt['id']);
        $a->act($appointment, 'cancel', channel: 'owner');
        self::assertCount(4, $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $id)['slots']);
        self::assertSame($receipt, $this->book($f, $id, $appointment));
        self::assertSame('cancelled', $a->records->find($a->student, $a->pursuit, $appointment)->facts()['status']);
        $new = $this->book($f, $id);
        self::assertNotSame($receipt['id'], $new['id']);
    }

    public function testUnicodeAvailabilityAndClosureStayEncryptedAndOriginalMigrationsRetrySafely(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $receipt = $f->service()->create(
            $a->host,
            'staff',
            EntityId::generate(),
            '2026-08-11T10:00',
            '2026-08-11T12:00',
            '-07:00',
            30,
            str_repeat('😀', 1000)
        );
        $id = new EntityId($receipt['id']);
        $f->service()->close($a->host, 'staff', $id, 0, str_repeat('😀', 1000));
        $row = $s->sql->rows('sis_advising_availability', ['id' => $id->value], 1)[0];
        self::assertLessThanOrEqual(32768, strlen($row['sealed_data']));
        self::assertStringNotContainsString('😀', json_encode($row, JSON_UNESCAPED_UNICODE));
        $before = $s->sql->rows('sis_advising_availability');
        $migration = new \CreateSisAdvisingAvailability('1');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $s->connection,
            'test_migrations'
        )]));
        $migration->up();
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function testAlteredRetainedBookingEvidenceCannotBecomeAnAcceptedOriginal(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $window = new EntityId($f->offer()['id']);
        $a->sis->access->actor = $a->owner;
        $id = new EntityId($this->book($f, $window)['id']);
        $data = $a->records->find($a->student, $a->pursuit, $id)->facts();
        $data['booking']['window_id'] = EntityId::generate()->value;
        $this->rejects(fn() => AdvisingAppointment::restore($data), CorruptHistoryException::class);
    }
}
