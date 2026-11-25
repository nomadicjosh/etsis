<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\AppointmentTime;
use Domain\Advising\AvailabilityPattern;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;
use Tests\Support\AdvisingRecoveryServices;
use Tests\Support\WorkflowRecoveryFixture;

/** Genuine services on fictional SQLite or explicitly opted-in native storage; never application rows. */
final class AdvisingWorkflowRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $recovery;
    private AdvisingAvailabilityFixture $availability;
    private AdvisingRecoveryServices $commands;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The isolated recovery checks require POSIX process signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        require_once dirname(__DIR__) . '/Support/AdvisingRecoveryServices.php';
        $this->recovery = new WorkflowRecoveryFixture();
        $this->recovery->sis->clock->time = $this->recovery->sis->clock->time->setTimezone(
            new \DateTimeZone('America/Los_Angeles'),
        );
        $this->availability = new AdvisingAvailabilityFixture($this->recovery->sis);
        $this->commands = new AdvisingRecoveryServices($this->recovery->sis);
    }

    protected function tearDown(): void
    {
        if (isset($this->recovery)) {
            $this->recovery->close();
        }
    }

    private function schedule(): array
    {
        $a = $this->availability->appointments;
        return ['student' => $a->student->value, 'pursuit' => $a->pursuit->value, 'channel' => 'staff',
            'id' => EntityId::generate()->value, 'host' => $a->host->value, 'start' => '2026-08-11T10:00',
            'end' => '2026-08-11T10:30', 'timezone' => 'America/Los_Angeles',
            'message' => 'Fictional original meeting.'];
    }

    private function action(string $id, string $action, int $version, ?AppointmentTime $time = null): array
    {
        $a = $this->availability->appointments;
        return ['student' => $a->student->value, 'pursuit' => $a->pursuit->value, 'channel' => 'staff', 'id' => $id,
            'operation' => EntityId::generate()->value, 'action' => $action, 'version' => $version,
            'time' => $time?->facts(), 'message' => 'Fictional ' . $action . ' explanation.'];
    }

    private function booking(string $window): array
    {
        $a = $this->availability->appointments;
        return ['student' => $a->student->value, 'pursuit' => $a->pursuit->value, 'id' => EntityId::generate()->value,
            'window' => $window, 'version' => 0, 'start' => '2026-08-11T10:00'];
    }

    private function windowClosure(string $id): array
    {
        return ['host' => $this->availability->appointments->host->value, 'channel' => 'staff',
            'id' => $id, 'version' => 0, 'message' => 'Fictional window closure.'];
    }

    private function series(string $through = '2026-08-25', string $message = 'Fictional series instructions.'): array
    {
        $a = $this->availability->appointments;
        $review = $this->commands->availability()->reviewSeries($a->host, 'staff', new AvailabilityPattern([
            'from' => '2026-08-11', 'through' => $through, 'start_time' => '10:00', 'end_time' => '12:00',
            'weekdays' => [2], 'interval_weeks' => 1, 'excluded_dates' => [], 'timezone' => 'America/Los_Angeles',
        ]), 30, $message);
        return ['host' => $a->host->value, 'channel' => 'staff', 'review' => $review];
    }

    private function seriesClosure(string $id): array
    {
        $a = $this->availability->appointments;
        return ['host' => $a->host->value, 'channel' => 'staff', 'review' =>
            $this->commands->availability()->reviewSeriesClose(
                $a->host,
                'staff',
                new EntityId($id),
                'Fictional series closure.'
            )];
    }

    private function interrupted(string $action, array $command, string $pause): void
    {
        $r = $this->recovery;
        $before = $r->facts();
        $worker = $r->start($action, $command, $pause);
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts(), 'An independent connection saw staged advising writes.');
        $r->kill($worker);
        self::assertSame($before, $r->facts(), 'Process loss left partial advising rows, receipts or audit.');
    }

    private function lostReply(string $action, array $command): array
    {
        $r = $this->recovery;
        $worker = $r->start($action, $command, loseReply: true);
        $r->send($worker, 'go');
        $r->await($worker, 'committed');
        $r->kill($worker);
        return $this->commands->run($action, $command);
    }

    private function quietFacts(): array
    {
        return array_diff_key($this->recovery->facts(), array_flip(['sis_advising_appointments',
            'sis_advising_availability', 'sis_advising_availability_series', 'sis_audit']));
    }

    private function appointment(string $id): array
    {
        $a = $this->availability->appointments;
        return $a->records->find($a->student, $a->pursuit, new EntityId($id))->facts();
    }

    public function testScheduleProcessLossAndLostReplyPreserveOriginalReceiptAfterCancellation(): void
    {
        $command = $this->schedule();
        $before = $this->quietFacts();
        $this->interrupted('advising_schedule', $command, 'advising.appointment_scheduled');
        $created = $this->lostReply('advising_schedule', $command);
        self::assertSame($command['id'], $created['id']);
        $this->commands->run('advising_act', $this->action($created['id'], 'cancel', 0));
        $this->recovery->sis->clock->time = $this->recovery->sis->clock->time->modify('+2 days');
        $facts = $this->recovery->facts();
        self::assertSame($created, $this->commands->run('advising_schedule', $command));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame('cancelled', $this->appointment($created['id'])['status']);
        self::assertSame($before, $this->quietFacts());
    }

    public function testRescheduleIsAtomicAndOriginalRetryPreservesLaterCancellationAndOriginalTimes(): void
    {
        $schedule = $this->schedule();
        $created = $this->commands->run('advising_schedule', $schedule);
        $command = $this->action(
            $created['id'],
            'reschedule',
            0,
            new AppointmentTime('2026-08-11T11:00', '2026-08-11T11:30', 'America/Los_Angeles')
        );
        $this->interrupted('advising_act', $command, 'advising.appointment_reschedule');
        $receipt = $this->lostReply('advising_act', $command);
        self::assertSame('2026-08-11T10:00', $this->appointment($created['id'])['actions'][0]['time']['start']);
        $this->commands->run('advising_act', $this->action($created['id'], 'cancel', 1));
        $facts = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->run('advising_act', $command));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame('cancelled', $this->appointment($created['id'])['status']);
    }

    #[DataProvider('firstOutcomes')]
    public function testRescheduleAndCancellationRecheckTheCommittedAppointmentVersion(bool $commit): void
    {
        $r = $this->recovery;
        $created = $this->commands->run('advising_schedule', $this->schedule());
        $move = $this->action(
            $created['id'],
            'reschedule',
            0,
            new AppointmentTime('2026-08-11T11:00', '2026-08-11T11:30', 'America/Los_Angeles')
        );
        $cancel = $this->action($created['id'], 'cancel', 0);
        $first = $r->start('advising_act', $move, 'advising.appointment_reschedule');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_act', $cancel);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        $record = $this->appointment($created['id']);
        self::assertSame(1, $record['version']);
        self::assertSame($commit ? 'scheduled' : 'cancelled', $record['status']);
        self::assertSame($commit ? '2026-08-11T11:00' : '2026-08-11T10:00', $record['time']['start']);
    }

    public function testDatedWindowCreateAndClosureRecoverTheirOriginalReceiptsAfterProcessLoss(): void
    {
        $a = $this->availability->appointments;
        $c = ['host' => $a->host->value, 'channel' => 'staff', 'id' => EntityId::generate()->value,
            'start' => '2026-08-11T10:00', 'end' => '2026-08-11T12:00', 'timezone' => 'America/Los_Angeles',
            'minutes' => 30, 'message' => 'Fictional dated offer.'];
        $quiet = $this->quietFacts();
        $this->interrupted('advising_window_create', $c, 'advising.availability_created');
        $created = $this->lostReply('advising_window_create', $c);
        $close = $this->windowClosure($created['id']);
        $this->interrupted('advising_window_close', $close, 'advising.availability_closed');
        $closed = $this->lostReply('advising_window_close', $close);
        $this->recovery->sis->clock->time = $this->recovery->sis->clock->time->modify('+2 days');
        $facts = $this->recovery->facts();
        self::assertSame($created, $this->commands->run('advising_window_create', $c));
        self::assertSame($closed, $this->commands->run('advising_window_close', $close));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame($quiet, $this->quietFacts());
    }

    #[DataProvider('outcomes')]
    public function testAttendanceAndLaterCorrectionPreserveOriginalActionsAfterProcessLoss(string $outcome): void
    {
        $created = $this->commands->run('advising_schedule', $this->schedule());
        $this->recovery->sis->clock->time = $this->recovery->sis->clock->time->modify('+2 days');
        $command = $this->action($created['id'], $outcome, 0);
        $quiet = $this->quietFacts();
        $this->interrupted('advising_act', $command, 'advising.appointment_' . $outcome);
        $original = $this->lostReply('advising_act', $command);
        $opposite = $outcome === 'held' ? 'no_show' : 'held';
        $correction = $this->action($created['id'], 'correct_' . $opposite, 1);
        $this->interrupted('advising_act', $correction, 'advising.appointment_correct_' . $opposite);
        $receipt = $this->lostReply('advising_act', $correction);
        $facts = $this->recovery->facts();
        self::assertSame($original, $this->commands->run('advising_act', $command));
        self::assertSame($receipt, $this->commands->run('advising_act', $correction));
        self::assertSame($facts, $this->recovery->facts());
        $record = $this->appointment($created['id']);
        self::assertSame($opposite, $record['status']);
        self::assertSame($outcome, $record['actions'][1]['action']);
        self::assertSame($quiet, $this->quietFacts());
    }

    public static function outcomes(): array
    {
        return [['held'], ['no_show']];
    }

    public function testBookingProcessLossAndOriginalReceiptSurviveWindowClosureAndOwnerCancellation(): void
    {
        $a = $this->availability->appointments;
        $s = $this->recovery->sis;
        $window = $this->availability->offer()['id'];
        $c = $this->booking($window);
        $s->access->actor = $a->owner;
        $quiet = $this->quietFacts();
        $this->interrupted('advising_book', $c, 'advising.appointment_self_booked');
        $receipt = $this->lostReply('advising_book', $c);
        $s->access->actor = $a->staff;
        $this->commands->run('advising_window_close', $this->windowClosure($window));
        $s->access->actor = $a->owner;
        $cancel = $this->action($c['id'], 'cancel', 0);
        $cancel['channel'] = 'owner';
        $this->commands->run('advising_act', $cancel);
        $s->clock->time = $s->clock->time->modify('+2 days');
        $facts = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->run('advising_book', $c));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame($window, $this->appointment($c['id'])['booking']['window_id']);
        self::assertSame('cancelled', $this->appointment($c['id'])['status']);
        self::assertSame($quiet, $this->quietFacts());
    }

    public function testDuplicateOwnerBookingsShareOneAppointmentAndReceipt(): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $c = $this->booking($this->availability->offer()['id']);
        $r->sis->access->actor = $a->owner;
        $first = $r->start('advising_book', $c, 'advising.appointment_self_booked');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_book', $c);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        $r->send($first, 'continue');
        $one = $r->result($first);
        $two = $r->result($second);
        self::assertSame('accepted', $one['state']);
        self::assertSame($one, $two);
        self::assertCount(1, $r->sis->sql->rows('sis_advising_appointments'));
    }

    private function secondStudent(): array
    {
        $a = $this->availability->appointments;
        $s = $this->recovery->sis;
        $owner = $s->user();
        $student = $s->student($owner)->id;
        $pursuit = new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $student->value], 1)[0]['id']);
        $advisors = $s->advisors();
        $view = $advisors->view($student, $pursuit);
        $review = $advisors->review(
            $student,
            $pursuit,
            [['id' => EntityId::generate()->value,
            'advisor_person_id' => $a->host->value, 'role' => 'primary',
            'starts_on' => '2026-01-01', 'ends_on' => null]],
            $view['revision'],
            'Fictional second advisee.'
        );
        $advisors->commit(
            $student,
            $pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        return ['owner' => $owner, 'student' => $student->value, 'pursuit' => $pursuit->value];
    }

    public static function firstOutcomes(): array
    {
        return ['first commits' => [true], 'first loses process' => [false]];
    }

    #[DataProvider('firstOutcomes')]
    public function testTwoStudentsCompeteForOneActualSlotWithoutDoubleBooking(bool $commit): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $other = $this->secondStudent();
        $window = $this->availability->offer()['id'];
        $one = $this->booking($window);
        $two = $this->booking($window);
        $two['student'] = $other['student'];
        $two['pursuit'] = $other['pursuit'];
        $quiet = $this->quietFacts();
        $r->sis->access->actor = $a->owner;
        $first = $r->start('advising_book', $one, 'advising.appointment_self_booked');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $r->sis->access->actor = $other['owner'];
        $second = $r->start('advising_book', $two);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        $rows = $r->sis->sql->rows('sis_advising_appointments');
        self::assertCount(1, $rows);
        self::assertSame($commit ? $one['id'] : $two['id'], $rows[0]['id']);
        self::assertSame($quiet, $this->quietFacts());
    }

    #[DataProvider('firstOutcomes')]
    public function testWindowClosureSerializesWithAWaitingOwnerBooking(bool $commit): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $window = $this->availability->offer()['id'];
        $closure = $this->windowClosure($window);
        $first = $r->start('advising_window_close', $closure, 'advising.availability_closed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $r->sis->access->actor = $a->owner;
        $second = $r->start('advising_book', $this->booking($window));
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        self::assertCount($commit ? 0 : 1, $r->sis->sql->rows('sis_advising_appointments'));
        self::assertSame(
            $commit ? 'closed' : 'open',
            $this->availability->records->find(new EntityId($window))->facts()['status']
        );
    }

    #[DataProvider('firstOutcomes')]
    public function testConfirmedBookingSurvivesAWaitingWindowClosure(bool $commit): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $window = $this->availability->offer()['id'];
        $c = $this->booking($window);
        $r->sis->access->actor = $a->owner;
        $first = $r->start('advising_book', $c, 'advising.appointment_self_booked');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $r->sis->access->actor = $a->staff;
        $second = $r->start('advising_window_close', $this->windowClosure($window));
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame('accepted', $r->result($second)['state']);
        self::assertCount($commit ? 1 : 0, $r->sis->sql->rows('sis_advising_appointments'));
        if ($commit) {
            self::assertSame('scheduled', $this->appointment($c['id'])['status']);
        }
        self::assertSame('closed', $this->availability->records->find(new EntityId($window))->facts()['status']);
    }

    public function testRecurringOfferProcessLossAndLostReplyPreserveAllMembersAndOriginalRetry(): void
    {
        $c = $this->series();
        $quiet = $this->quietFacts();
        $this->interrupted('advising_series_create', $c, 'advising.recurring_availability_created');
        $receipt = $this->lostReply('advising_series_create', $c);
        self::assertCount(3, $this->recovery->sis->sql->rows('sis_advising_availability'));
        self::assertCount(1, $this->recovery->sis->sql->rows('sis_advising_availability_series'));
        $this->commands->run('advising_series_close', $this->seriesClosure($receipt['id']));
        $this->recovery->sis->clock->time = $this->recovery->sis->clock->time->modify('+2 days');
        $facts = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->run('advising_series_create', $c));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame($quiet, $this->quietFacts());
    }

    public function testRecurringClosureRecoveryPreservesAppointmentsIndividualClosuresAndExpiredMembers(): void
    {
        $a = $this->availability->appointments;
        $s = $this->recovery->sis;
        $create = $this->series();
        $this->commands->run('advising_series_create', $create);
        $ids = $create['review']['window_ids'];
        $s->access->actor = $a->owner;
        $booking = $this->booking($ids[0]);
        $this->commands->run('advising_book', $booking);
        $appointment = $this->appointment($booking['id']);
        $s->access->actor = $a->staff;
        $this->commands->run('advising_window_close', $this->windowClosure($ids[0]));
        $s->clock->time = $s->clock->time->modify('+9 days');
        $close = $this->seriesClosure($create['review']['series_id']);
        self::assertSame([$ids[2]], $close['review']['window_ids']);
        $quiet = $this->quietFacts();
        $this->interrupted('advising_series_close', $close, 'advising.recurring_availability_closed');
        $receipt = $this->lostReply('advising_series_close', $close);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $facts = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->run('advising_series_close', $close));
        self::assertSame($facts, $this->recovery->facts());
        self::assertSame($appointment, $this->appointment($booking['id']));
        self::assertSame('open', $this->availability->records->find(new EntityId($ids[1]))->facts()['status']);
        self::assertSame($quiet, $this->quietFacts());
    }

    public function testDuplicateRecurringOffersShareOneParentAndExactMembers(): void
    {
        $r = $this->recovery;
        $c = $this->series();
        $first = $r->start('advising_series_create', $c, 'advising.recurring_availability_created');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_series_create', $c);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        $r->send($first, 'continue');
        $one = $r->result($first);
        $two = $r->result($second);
        self::assertSame('accepted', $one['state']);
        self::assertSame($one, $two);
        self::assertCount(1, $r->sis->sql->rows('sis_advising_availability_series'));
        self::assertSame($c['review']['window_ids'], $one['result']['window_ids']);
        self::assertCount(3, $r->sis->sql->rows('sis_advising_availability'));
    }

    public function testMaximumTwentyWindowOfferAndClosureRecoverAsWholeTransactions(): void
    {
        $r = $this->recovery;
        $c = $this->series('2026-12-22', str_repeat('😀', 1000));
        self::assertCount(20, $c['review']['window_ids']);
        $quiet = $this->quietFacts();
        $this->interrupted('advising_series_create', $c, 'advising.recurring_availability_created');
        $created = $this->lostReply('advising_series_create', $c);
        $close = $this->seriesClosure($created['id']);
        $this->interrupted('advising_series_close', $close, 'advising.recurring_availability_closed');
        $closed = $this->lostReply('advising_series_close', $close);
        self::assertCount(20, $closed['window_ids']);
        self::assertSame(str_repeat('😀', 1000), $this->availability->records->find(
            new EntityId($c['review']['window_ids'][0]),
        )->facts()['message']);
        $rows = $r->sis->sql->rows('sis_advising_availability');
        self::assertCount(20, $rows);
        self::assertSame(['closed'], array_values(array_unique(array_column($rows, 'status'))));
        $facts = $r->facts();
        self::assertSame($created, $this->commands->run('advising_series_create', $c));
        self::assertSame($closed, $this->commands->run('advising_series_close', $close));
        self::assertSame($facts, $r->facts());
        self::assertSame($quiet, $this->quietFacts());
    }

    #[DataProvider('firstOutcomes')]
    public function testSeriesClosureAndIndividualClosureSerializeWithoutReplacingReasons(bool $commit): void
    {
        $r = $this->recovery;
        $c = $this->series();
        $this->commands->run('advising_series_create', $c);
        $close = $this->seriesClosure($c['review']['series_id']);
        $individual = $this->windowClosure($c['review']['window_ids'][0]);
        $first = $r->start('advising_series_close', $close, 'advising.recurring_availability_closed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_window_close', $individual);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        $view = $this->commands->availability()->seriesView(
            new EntityId($c['host']),
            'staff',
            new EntityId($c['review']['series_id'])
        );
        self::assertSame($commit ? 'closed' : 'open', $view['series']['status']);
        self::assertSame(
            $commit ? $close['review']['reason'] : $individual['message'],
            $view['windows'][0]['close_reason']
        );
        self::assertSame($commit ? [] : array_slice($c['review']['window_ids'], 1), $view['remaining_ids']);
    }
}
