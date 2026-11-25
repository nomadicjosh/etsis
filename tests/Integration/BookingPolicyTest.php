<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\BookingPolicySnapshot;
use Domain\Advising\Service\BookingPolicyService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;

final class BookingPolicyTest extends TestCase
{
    private function fixture(): AdvisingAvailabilityFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        return new AdvisingAvailabilityFixture();
    }

    private function change(AdvisingAvailabilityFixture $f, array $rules): array
    {
        $p = $f->appointments->policies();
        $r = $p->review(
            array_replace(BookingPolicySnapshot::DEFAULTS, $rules),
            $p->snapshot()->revision,
            'Fictional policy change.'
        );
        return $this->save($f, $r);
    }

    private function save(
        AdvisingAvailabilityFixture $f,
        array $r,
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): array {
        return $f->appointments->policies($audit)->save(
            new EntityId($r['id']),
            $r['rules'],
            $r['version'],
            $r['reason'],
            $r['review_token']
        );
    }

    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
            self::fail('Expected a refused booking-policy action.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    public function testIndependentPolicyAuthorityAndOriginalRetriesAfterLaterChanges(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $p = $a->policies();
        self::assertSame(0, $p->view()['current']['revision']);
        $s->access->staff = false;
        $s->access->grants = ['sis:advising:appointments:manage', 'sis:student:read'];
        $this->rejects(fn() => $p->view(), AccessDeniedException::class);
        $s->access->grants[] = BookingPolicyService::PERMISSION;
        $r = $p->review(BookingPolicySnapshot::DEFAULTS, 0, 'Fictional first setup.');
        $receipt = $this->save($f, $r);
        $this->change($f, ['maximum_active' => 1]);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $count = count($s->sql->rows('sis_audit'));
        self::assertSame($receipt, $this->save($f, $r));
        self::assertSame($count, count($s->sql->rows('sis_audit')));
        self::assertSame(1, $p->snapshot()->rules['maximum_active']);
        self::assertCount(2, $p->history()['items']);
        self::assertStringNotContainsString('actor_id', json_encode($p->history()));
    }

    public function testStaleChangedAndExpiredReviewsNeverWriteAPartialPolicy(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $p = $a->policies();
        $r = $p->review(BookingPolicySnapshot::DEFAULTS, 0, 'Fictional review.');
        $bad = $r;
        $bad['rules']['maximum_active'] = 1;
        $this->rejects(fn() => $this->save($f, $bad), ValidationException::class);
        $a->sis->clock->time = $a->sis->clock->time->modify('+20 minutes');
        $this->rejects(fn() => $this->save($f, $r), ConcurrentModificationException::class);
        self::assertSame([], $a->sis->sql->rows('sis_advising_booking_policies'));
        $fresh = $p->review(BookingPolicySnapshot::DEFAULTS, 0, 'Fictional new review.');
        $this->change($f, ['maximum_active' => 3]);
        $this->rejects(fn() => $this->save($f, $fresh), ConcurrentModificationException::class);
    }

    public function testRequiredAuditAndFinalDutyFailureRollBackPolicyAndCoupledWrites(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $a->policies()->review(BookingPolicySnapshot::DEFAULTS, 0, 'Fictional setup.');
        $s->access->staff = false;
        $s->access->grants = [BookingPolicyService::PERMISSION];
        $audit = $a->audit(function () use ($s): void {
            $s->access->grants = [];
        });
        $this->rejects(fn() => $this->save($f, $r, $audit), AccessDeniedException::class);
        self::assertSame([], $s->sql->rows('sis_advising_booking_policies'));
        $s->access->staff = true;
        $audit = $a->audit(function (): void {
            throw new \RuntimeException('Fictional audit refusal.');
        });
        $this->rejects(fn() => $this->save($f, $r, $audit), \RuntimeException::class);
        self::assertSame([], $s->sql->rows('sis_advising_booking_policies'));
    }

    public function testPolicyLimitsAllSchedulingChannelsAndPreservesReschedulingAtCapacity(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $this->change($f, ['maximum_active' => 1, 'notice_minutes' => 60, 'horizon_days' => 2]);
        $created = $a->schedule();
        self::assertTrue($a->service()->listing($a->student, $a->pursuit, 'staff')['capacity_reached']);
        $this->rejects(
            fn() => $a->schedule(time: $a->time('2026-08-12T10:00', '2026-08-12T10:30')),
            ConcurrentModificationException::class
        );
        $a->act(new EntityId($created['id']), 'reschedule', time: $a->time('2026-08-12T10:00', '2026-08-12T10:30'));
        $record = $a->records->find($a->student, $a->pursuit, new EntityId($created['id']))->facts();
        self::assertSame(1, $record['actions'][0]['booking_policy']['revision']);
        self::assertSame(1, $record['actions'][1]['booking_policy']['revision']);
        $a->act(new EntityId($created['id']), 'cancel', 1);
        $this->rejects(
            fn() => $a->schedule(time: $a->time('2026-08-13T10:00', '2026-08-13T10:30')),
            ValidationException::class
        );
    }

    public function testOwnerChoicesFilterTimeRulesAndCapacityButOriginalBookingRetriesRemainExact(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $window = new EntityId($f->offer()['id']);
        $this->change($f, ['maximum_active' => 1, 'notice_minutes' => 1470, 'horizon_days' => 3]);
        $s->access->actor = $a->owner;
        $choices = $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $window);
        self::assertSame('2026-08-11T10:30', $choices['slots'][0]['start']);
        $id = EntityId::generate();
        $receipt = $a->service()->book($a->student, $a->pursuit, $id, $window, 0, '2026-08-11T10:30');
        self::assertTrue($a->service()->bookingChoices($a->student, $a->pursuit)['capacity_reached']);
        $s->access->actor = $a->staff;
        $this->change($f, ['maximum_active' => 1, 'notice_minutes' => 2000, 'horizon_days' => 3]);
        $s->access->actor = $a->owner;
        self::assertSame($receipt, $a->service()->book($a->student, $a->pursuit, $id, $window, 0, '2026-08-11T10:30'));
        self::assertSame(1, $a->records->find(
            $a->student,
            $a->pursuit,
            $id
        )->facts()['actions'][0]['booking_policy']['revision']);
    }

    public function testPolicyDriftDuringAppointmentAuditRollsBackTheAppointmentAndPolicyChange(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $a->policies()->review(array_replace(
            BookingPolicySnapshot::DEFAULTS,
            ['maximum_active' => 1]
        ), 0, 'Fictional competing policy.');
        $audit = $a->audit(function () use ($f, $r): void {
            $this->save($f, $r);
        });
        $this->rejects(fn() => $a->service($audit)->schedule(
            $a->student,
            $a->pursuit,
            'staff',
            EntityId::generate(),
            $a->host,
            '2026-08-11T10:00',
            '2026-08-11T10:30',
            $s->clock->now()->getTimezone()->getName(),
            'Fictional meeting.'
        ), ConcurrentModificationException::class);
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
        self::assertSame([], $s->sql->rows('sis_advising_booking_policies'));
    }

    public function testPolicyHistoryPagesAndEncryptedRoutingDamageAreBounded(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        for ($i = 0; $i < 11; $i++) {
            $this->change($f, ['maximum_active' => $i + 1]);
        }
        $page = $a->policies()->history();
        self::assertCount(10, $page['items']);
        self::assertCount(1, $a->policies()->history($page['next_before'])['items']);
        $row = $s->sql->rows('sis_advising_booking_policies', ['id' => $page['items'][0]['id']], 1)[0];
        self::assertStringNotContainsString('Fictional', $row['sealed_data']);
        $s->sql->update('sis_advising_booking_policies', ['revision' => 12], ['id' => $row['id']]);
        $this->rejects(fn() => $a->policies()->view(), \Domain\Shared\EventSourcing\CorruptHistoryException::class);
    }

    public function testEarlierAppointmentsKeepTheirOriginalShapeAndReceiptsAfterPolicySetup(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = EntityId::generate();
        $old = \Domain\Advising\AdvisingAppointment::create(
            $id,
            $a->student,
            $a->pursuit,
            $a->owner,
            $a->host,
            $a->advisor,
            $a->staff,
            $a->time(),
            'Fictional meeting instructions.',
            '2026-08-10T17:00:00Z'
        );
        $a->records->save($old, null);
        $original = $old->facts();
        $expected = $old->retry($id, $a->staff, $old->intent(
            'schedule',
            -1,
            $a->time(),
            'Fictional meeting instructions.'
        ));
        $history = $s->sql->rows('sis_history_events');
        $this->change($f, ['maximum_active' => 1, 'notice_minutes' => 3000, 'horizon_days' => 4]);
        self::assertSame($expected, $a->schedule($id));
        self::assertSame($original, $a->records->find($a->student, $a->pursuit, $id)->facts());
        self::assertArrayNotHasKey('booking_policy', $old->view()['changes'][0]);
        $a->act($id, 'cancel');
        self::assertSame($history, $s->sql->rows('sis_history_events'));
    }

    public function testScheduleAndRescheduleRetriesUseEachOriginalPolicyAfterLaterChanges(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = EntityId::generate();
        $first = $a->schedule($id);
        $this->change($f, ['maximum_active' => 1]);
        $operation = EntityId::generate();
        $time = $a->time('2026-08-12T10:00', '2026-08-12T10:30');
        $run = fn() => $a->service()->act(
            $a->student,
            $a->pursuit,
            'staff',
            $id,
            $operation,
            'reschedule',
            0,
            $time,
            'Fictional rescheduled appointment.'
        );
        $second = $run();
        $this->change($f, ['notice_minutes' => 6000, 'horizon_days' => 5]);
        self::assertSame($first, $a->schedule($id));
        self::assertSame($second, $run());
        $view = $a->records->find($a->student, $a->pursuit, $id)->view();
        self::assertSame(0, $view['changes'][0]['booking_policy']['revision']);
        self::assertSame(1, $view['changes'][1]['booking_policy']['revision']);
        $this->rejects(fn() => $a->act($id, 'reschedule', 1, time: $a->time(
            '2026-08-13T10:00',
            '2026-08-13T10:30'
        )), ValidationException::class);
    }

    public function testAssignedAdvisorCannotBypassMinimumNoticeOrConfigurePolicy(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->change($f, ['notice_minutes' => 1470, 'horizon_days' => 3]);
        $s->access->actor = $a->advisor;
        $s->access->staff = false;
        $s->access->grants = ['sis:advising:appointments:manage'];
        $this->rejects(fn() => $a->policies()->view(), AccessDeniedException::class);
        $this->rejects(fn() => $a->schedule(channel: 'advisor'), ValidationException::class);
        self::assertSame('scheduled', $a->schedule(
            channel: 'advisor',
            time: $a->time('2026-08-11T10:30', '2026-08-11T11:00')
        )['status']);
    }

    public function testFinalReviewExpiryAndClockDriftRollBackFreshWrites(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $a->policies()->review(BookingPolicySnapshot::DEFAULTS, 0, 'Fictional setup.');
        $audit = $a->audit(function () use ($s): void {
            $s->clock->time = $s->clock->time->modify('+20 minutes');
        });
        $this->rejects(fn() => $this->save($f, $r, $audit), ConcurrentModificationException::class);
        self::assertSame([], $s->sql->rows('sis_advising_booking_policies'));
        $s->clock->time = $s->clock->time->modify('-20 minutes');
        $this->change($f, ['notice_minutes' => 1440, 'horizon_days' => 3]);
        $audit = $a->audit(function () use ($s): void {
            $s->clock->time = $s->clock->time->modify('+1 minute');
        });
        $this->rejects(fn() => $a->service($audit)->schedule(
            $a->student,
            $a->pursuit,
            'staff',
            EntityId::generate(),
            $a->host,
            '2026-08-11T10:00',
            '2026-08-11T10:30',
            $s->clock->now()->getTimezone()->getName(),
            'Fictional boundary meeting.'
        ), ValidationException::class);
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
    }

    public function testMigrationRetryPreservesOriginalFactsAndGrantsWithoutInventingPolicy(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $a->schedule();
        $f->offer();
        $tables = ['sis_advising_appointments', 'sis_advising_availability', 'sis_history_events', 'sis_audit',
            'sis_access_permissions', 'sis_access_implications', 'sis_access_role_permissions',
            'sis_access_user_permissions', 'sis_access_user_roles'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        $migration = new \CreateSisAdvisingBookingPolicies('1');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($s->connection, 'test_migrations')]));
        $s->connection->pdo->exec('PRAGMA query_only=ON');
        $migration->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], $s->sql->rows($table));
        }
        self::assertSame([], $s->sql->rows('sis_advising_booking_policies'));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
