<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\BookingPolicySnapshot;
use Domain\Advising\Repository\AdvisingClassScheduleRepository;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;
use Tests\Support\RegistrationScheduleFixture;

final class AdvisingClassCalendarTest extends TestCase
{
    private function fixture(): AdvisingAvailabilityFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        require_once dirname(__DIR__) . '/Support/RegistrationScheduleFixture.php';
        return new AdvisingAvailabilityFixture();
    }

    private function policy(AdvisingAvailabilityFixture $f, string $mode = 'block'): array
    {
        $p = $f->appointments->policies();
        $r = $p->review(
            BookingPolicySnapshot::DEFAULTS + ['student_class_mode' => $mode],
            $p->snapshot()->revision,
            'Fictional student class policy.'
        );
        return $p->save(new EntityId($r['id']), $r['rules'], $r['version'], $r['reason'], $r['review_token']);
    }

    private function publish(AdvisingAvailabilityFixture $f, Section $section, array $times): void
    {
        new RegistrationScheduleFixture($f->appointments->sis)->publish($section, $times);
    }

    private function enroll(AdvisingAvailabilityFixture $f, bool $published = true): Section
    {
        $a = $f->appointments;
        $s = $a->sis;
        $section = $s->section(fee: 0);
        if ($published) {
            $this->publish($f, $section, [['2026-08-11T17:00:00Z', '2026-08-11T17:30:00Z']]);
        }
        $s->service->register($a->student, $section->id, true);
        return $section;
    }

    private function rejects(callable $run, string $type = ValidationException::class): void
    {
        try {
            $run();
            self::fail('Expected a refused class-overlap action.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($type, $e);
            self::assertStringNotContainsString('CS-', $e->getMessage());
        }
    }

    public function testOfficeAndAssignedAdvisorRespectClassConflictsWithoutCreatingAcademicFacts(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->enroll($f);
        $this->policy($f);
        $history = $s->sql->rows('sis_history_events');
        $this->rejects(fn() => $a->schedule());
        $s->access->actor = $a->advisor;
        $s->access->staff = false;
        $s->access->grants = ['sis:advising:appointments:manage'];
        $this->rejects(fn() => $a->schedule(channel: 'advisor'));
        self::assertSame('scheduled', $a->schedule(
            channel: 'advisor',
            time: $a->time('2026-08-11T10:30', '2026-08-11T11:00')
        )['status']);
        self::assertSame($history, $s->sql->rows('sis_history_events'));
    }

    public function testOwnerSlotsExcludeClassesAndConfirmationCannotBypassTheFilter(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $this->enroll($f);
        $this->policy($f);
        $window = new EntityId($f->offer()['id']);
        $s->access->actor = $a->owner;
        $choices = $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $window);
        self::assertCount(3, $choices['slots']);
        self::assertSame('2026-08-11T10:30', $choices['slots'][0]['start']);
        self::assertStringNotContainsString($section->id->value, json_encode($choices));
        self::assertStringNotContainsString($section->code, json_encode($choices));
        $this->rejects(fn() => $a->service()->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $window,
            0,
            '2026-08-11T10:00'
        ));
        self::assertSame('scheduled', $a->service()->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $window,
            0,
            '2026-08-11T10:30'
        )['status']);
    }

    public function testChangingClassesDoesNotRewriteAcceptedSchedulesOrOriginalRetries(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $id = EntityId::generate();
        $original = $a->schedule($id);
        $this->enroll($f);
        $this->policy($f);
        self::assertSame($original, $a->schedule($id));
        $this->rejects(fn() => $a->act($id, 'reschedule', time: $a->time()));
        $operation = EntityId::generate();
        $next = $a->time('2026-08-11T11:00', '2026-08-11T11:30');
        $run = fn() => $a->service()->act(
            $a->student,
            $a->pursuit,
            'staff',
            $id,
            $operation,
            'reschedule',
            0,
            $next,
            'Fictional new time.'
        );
        $result = $run();
        $section = $s->registration->section(new EntityId($s->sql->rows('sis_enrollments')[0]['section_id']));
        $this->publish($f, $section, [['2026-08-11T18:00:00Z', '2026-08-11T19:00:00Z']]);
        self::assertSame($result, $run());
        $record = $a->records->find($a->student, $a->pursuit, $id)->facts();
        self::assertArrayNotHasKey('student_class_mode', $record['actions'][0]['booking_policy']['rules']);
        self::assertSame('block', $record['actions'][1]['booking_policy']['rules']['student_class_mode']);
        $a->act($id, 'cancel', 1);
        self::assertSame('cancelled', $a->records->find($a->student, $a->pursuit, $id)->facts()['status']);
    }

    public function testUnpublishedAndExplicitEmptyPlansDoNotInventClassTimesAndClosedWorkIsExcluded(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $this->enroll($f, false);
        $this->policy($f);
        $first = new EntityId($a->schedule()['id']);
        $a->act($first, 'cancel');
        $this->publish($f, $section, []);
        $second = new EntityId($a->schedule()['id']);
        $a->act($second, 'cancel');
        $this->publish($f, $section, [['2026-08-11T17:00:00Z', '2026-08-11T17:30:00Z']]);
        $s->service->drop($a->student, $section->id);
        self::assertSame('scheduled', $a->schedule()['status']);
    }

    #[DataProvider('freshActions')]
    public function testFinalPublishedPlanChangesRollBackTheEntireFreshAction(string $action): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $this->enroll($f, false);
        $this->policy($f);
        $id = $action === 'reschedule' ? new EntityId($a->schedule()['id']) : EntityId::generate();
        $window = $action === 'book' || $action === 'choices' ? new EntityId($f->offer()['id']) : null;
        $before = $s->sql->rows('sis_history_events');
        $appointments = $s->sql->rows('sis_advising_appointments');
        $audit = $a->audit(function () use ($f, $section): void {
            $this->publish($f, $section, [['2026-08-11T17:00:00Z', '2026-08-11T17:30:00Z']]);
        });
        $service = $a->service($audit);
        if ($action === 'book' || $action === 'choices') {
            $s->access->actor = $a->owner;
        }
        $this->rejects(fn() => match ($action) {
            'schedule' => $service->schedule($a->student, $a->pursuit, 'staff', $id, $a->host,
                '2026-08-11T10:00', '2026-08-11T10:30', $s->clock->now()->getTimezone()->getName(),
                'Fictional meeting.'),
            'reschedule' => $service->act($a->student, $a->pursuit, 'staff', $id, EntityId::generate(),
                'reschedule', 0, $a->time(), 'Fictional changed time.'),
            'book' => $service->book($a->student, $a->pursuit, $id, $window, 0, '2026-08-11T10:00'),
            'choices' => $service->bookingChoices($a->student, $a->pursuit, windowId: $window),
        }, ConcurrentModificationException::class);
        self::assertSame($appointments, $s->sql->rows('sis_advising_appointments'));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public static function freshActions(): array
    {
        return [['schedule'], ['reschedule'], ['book'], ['choices']];
    }

    public function testCorruptPublishedPlansFailClosedWhileDefaultPolicyLeavesEarlierBehaviorAlone(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $this->enroll($f);
        $s->sql->update(
            'sis_section_meeting_plans',
            ['definition_hash' => str_repeat('0', 64)],
            ['id' => $section->id->value]
        );
        $id = new EntityId($a->schedule()['id']);
        $a->act($id, 'cancel');
        $this->policy($f);
        $this->rejects(fn() => $a->schedule(), CorruptHistoryException::class);
    }

    public function testOversizedSourcesFailBeforeAnyPublishedPlanOrPartialAvailabilityIsUsed(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $records = $this->createStub(AdvisingClassScheduleRepository::class);
        $records->method('enrollments')->willReturn(array_fill(0, 51, []));
        $this->rejects(fn() => $a->calendar($records)->read($a->student, $a->time()), ValidationException::class);
    }

    public function testFinalEnrollmentChangesRollBackCoupledAcademicAndAppointmentWrites(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $s->section(fee: 0);
        $this->publish($f, $section, [['2026-08-11T17:00:00Z', '2026-08-11T17:30:00Z']]);
        $this->policy($f);
        $history = $s->sql->rows('sis_history_events');
        $audit = $a->audit(function () use ($s, $a, $section): void {
            $s->service->register($a->student, $section->id, true);
        });
        $this->rejects(
            fn() => $a->service($audit)->schedule(
                $a->student,
                $a->pursuit,
                'staff',
                EntityId::generate(),
                $a->host,
                '2026-08-11T10:00',
                '2026-08-11T10:30',
                $s->clock->now()->getTimezone()->getName(),
                'Fictional class-source race.'
            ),
            ConcurrentModificationException::class
        );
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        self::assertSame($history, $s->sql->rows('sis_history_events'));
    }

    public function testEarlierLegacyRostersAreFilteredBeforeTheCurrentDateVerificationBound(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $section = $this->enroll($f, false);
        $this->policy($f);
        $term = $s->sql->rows('sis_terms', ['id' => $s->term->value], 1)[0];
        $term['id'] = EntityId::generate()->value;
        $term['code'] = 'FICTIONAL.OLD';
        $term['starts_on'] = '2025-01-01';
        $term['ends_on'] = '2025-06-30';
        $term['add_drop_ends_on'] = '2025-01-20';
        $s->sql->insert('sis_terms', $term);
        $row = $s->sql->rows('sis_enrollments')[0];
        // Explicit earlier legacy projections with unknown history; never fabricate corresponding events.
        for ($i = 0; $i < 51; $i++) {
            $old = new Section(
                EntityId::generate(),
                $section->courseId,
                new EntityId($term['id']),
                null,
                'FICTIONAL.OLD.' . $i,
                20,
                true,
                true,
                new \Domain\Finance\ValueObject\Money(0, 'USD')
            );
            $s->registration->addSection($old);
            $s->sql->insert('sis_enrollments', array_replace($row, ['id' => EntityId::generate()->value,
                'section_id' => $old->id->value, 'term_id' => $term['id']]));
        }
        $records = new \Infrastructure\Persistence\Repository\SqlAdvisingClassScheduleRepository($s->sql);
        self::assertCount(1, $records->enrollments($a->student, '2026-08-10', '2026-08-12'));
        $before = $s->sql->rows('sis_history_events');
        self::assertSame('scheduled', $a->schedule()['status']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }
}
