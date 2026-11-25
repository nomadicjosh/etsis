<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AvailabilityBooking;
use Domain\Advising\Repository\AdvisingAppointmentRepository;
use Domain\Advising\Repository\AdvisingAvailabilityRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\CalendarPeriod;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentPrimaryProgramLedger;
use Domain\Student\Service\StudentProgramStatusLedger;

use function Codefy\Framework\Helpers\trans;

final readonly class AdvisingAppointmentService
{
    public const string PERMISSION = 'sis:advising:appointments:manage';
    public const string CORRECT = 'sis:advising:appointments:correct';

    public function __construct(
        private AdvisingAppointmentRepository $records,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private StudentProgramStatusLedger $programs,
        private StudentPrimaryProgramLedger $primary,
        private AdvisorService $advisors,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private AdvisingAvailabilityRepository $availability,
        private BookingPolicyService $policies,
        private StudentClassCalendarService $classes,
        private AdvisorTeachingCalendarService $teaching,
    ) {
    }

    private function context(EntityId $student, EntityId $pursuit, ?HistoryReadBudget $budget = null): array
    {
        $s = $this->admissions->student($student);
        $p = $this->students->assignment($pursuit);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException(trans('Student program not found.'));
        }
        $budget ??= new HistoryReadBudget();
        $version = $this->programs->verify($p, $budget);
        $primary = $this->primary->documentContext($s, $budget);
        return ['owner' => $this->admissions->person($s->personId)->ownerUserId?->value,
            'person' => $s->personId->value, 'program_version' => $version, 'primary' => $primary,
            'current' => $s->active && $p->status === 'active' && $primary['assignment_id'] === $pursuit->value];
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($channel === 'owner') {
            if (
                $this->admissions->person($this->admissions->student($student)->personId)->ownerUserId?->value
                !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } else {
            if (
                $this->access->requirePermission(self::PERMISSION)->value !== $actor->value
                || match ($channel) {
                    'staff' => $this->access->requirePermission('sis:student:read')->value !== $actor->value,
                    'advisor' => $this->advisors->requireAdvisor($student, $pursuit)->value !== $actor->value,
                    default => true,
                }
            ) {
            throw new AccessDeniedException();
            }
        }
        if (
            ($expected !== null && $expected->value !== $actor->value)
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function finish(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        EntityId $actor,
        array $context,
        ?HistoryReadBudget $budget = null,
    ): void {
        $this->authorize($student, $pursuit, $channel, $actor);
        if ($context !== $this->context($student, $pursuit, $budget)) {
            throw new ConcurrentModificationException(trans('Student context changed. Refresh appointments.'));
        }
    }

    public function listing(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        bool $finished = false,
        ?EntityId $after = null,
        ?EntityId $selected = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $finished,
            $after,
            $actor,
            $selected,
): array {
            $context = $this->context($student, $pursuit);
            $hosts = $channel !== 'owner' && $context['current']
                ? $this->advisors->appointmentHosts($student, $pursuit, $channel) : [];
            $policy = $this->policies->snapshot();
            $capacity = $this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active'];
            $rows = $selected === null
                ? $this->records->page($student, $pursuit, $channel === 'owner' ? $actor : null, $finished, $after)
                : [$this->records->find($student, $pursuit, $selected)
                    ?? throw new RecordNotFoundException(trans('Advising appointment not found.'))];
            $items = array_slice($rows, 0, 10);
            $views = [];
            $hostSnapshots = [];
            $correctionAllowed = $this->canCorrect($channel, $actor);
            foreach ($items as $item) {
                $data = $item->facts();
                if ($channel === 'owner' && $data['owner_user_id'] !== $actor->value) {
                    throw new AccessDeniedException();
                }
                $host = $this->admissions->person(new EntityId($data['host_person_id']));
                $hostSnapshots[$host->id->value] = [$host->givenName, $host->familyName, $host->ownerUserId?->value];
                $changed = $data['owner_user_id'] !== $context['owner']
                    || $data['host_user_id'] !== $host->ownerUserId?->value;
                $canAct = $data['status'] === 'scheduled' && match ($channel) {
                    'owner' => $this->now() < $data['time']['starts_at'],
                    'advisor' => $data['host_user_id'] === $actor->value,
                    default => true,
                };
                $views[] = $item->view() + ['host_name' => $host->givenName . ' ' . $host->familyName,
                    'can_act' => $canAct, 'cancel_only' => $channel === 'owner' || $changed,
                    'can_reschedule' => $canAct && !$changed && $channel !== 'owner' && $context['current']
                        && $data['version'] < 10,
                    'can_correct' => !$changed && in_array($data['status'], ['held', 'no_show'], true)
                        && $item->corrections() < 5 && $correctionAllowed,
                    'new_operation' => EntityId::generate()->value];
            }
            $this->audit->record($actor, 'advising.appointments_read', $student);
            foreach ($items as $item) {
                $this->verify($item);
            }
            foreach ($hostSnapshots as $id => $snapshot) {
                $person = $this->admissions->person(new EntityId($id));
                if ($snapshot !== [$person->givenName, $person->familyName, $person->ownerUserId?->value]) {
                    throw new ConcurrentModificationException(trans('Advisor account changed. Refresh appointments.'));
                }
            }
            $this->finish($student, $pursuit, $channel, $actor, $context);
            if ($correctionAllowed && !$this->canCorrect($channel, $actor)) {
                throw new AccessDeniedException();
            }
            if ($hosts !== [] && $hosts !== $this->advisors->appointmentHosts($student, $pursuit, $channel)) {
                throw new ConcurrentModificationException(trans('Advisor assignments changed. Refresh appointments.'));
            }
            $this->policies->assertCurrent($policy);
            if ($capacity !== ($this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active'])) {
                throw new ConcurrentModificationException(trans(
                    'Active appointments changed. Refresh the current list.'
                ));
            }
            return ['items' => $views, 'next_after' => count($rows) > 10 ? end($items)->facts()['id'] : null,
                'booking_policy' => $policy->facts(),
                'capacity_reached' => $capacity,
                'new_id' => EntityId::generate()->value,
                'can_book' => !$capacity && $channel === 'owner' && $selected === null && $context['current'],
                'can_schedule' => !$capacity && $selected === null && $hosts !== [] && $context['owner'] !== null,
                'hosts' => array_map(static fn(array $h): array => array_intersect_key($h, array_flip([
                    'advisor_person_id', 'name', 'starts_on', 'ends_on',
                ])), $hosts), 'timezone' => $this->clock->now()->getTimezone()->getName(), 'now' => $this->now()];
        });
    }

    private function canCorrect(string $channel, EntityId $actor): bool
    {
        if ($channel !== 'staff') {
            return false;
        }
        try {
            return $this->access->requirePermission(self::CORRECT)->value === $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function bookable(AdvisingAvailability $window, array $hosts): void
    {
        $d = $window->facts();
        $date = substr($d['time']['start'], 0, 10);
        $matches = array_filter($hosts, static fn(array $h): bool =>
            $h['advisor_person_id'] === $d['host_person_id'] && $h['user_id'] === $d['host_user_id']
                && $h['starts_on'] <= $date && ($h['ends_on'] === null || $h['ends_on'] >= $date));
        if ($matches === [] || $d['time']['timezone'] !== $this->clock->now()->getTimezone()->getName()) {
            throw new AccessDeniedException(trans(
                'This advisor availability is no longer available for your program.'
            ));
        }
        if ($d['status'] !== 'open') {
            throw new ConcurrentModificationException(trans('Availability closed. Choose another window.'));
        }
    }

    /** Owner-only, with no appointment-management duty or other students' schedule details. */
    public function bookingChoices(
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $host = null,
        ?string $date = null,
        ?EntityId $windowId = null
    ): array {
        $actor = $this->authorize($student, $pursuit, 'owner');
        return $this->transactions->run(function () use ($student, $pursuit, $actor, $host, $date, $windowId): array {
            $context = $this->context($student, $pursuit);
            if (!$context['current']) {
                throw new AccessDeniedException(trans('Self-booking requires the current primary active program.'));
            }
            $hosts = $this->advisors->appointmentHosts($student, $pursuit, 'owner');
            $policy = $this->policies->snapshot();
            $capacity = $this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active'];
            $result = ['hosts' => array_values(array_reduce($hosts, static function (array $all, array $h): array {
                $all[$h['advisor_person_id']] = ['id' => $h['advisor_person_id'], 'name' => $h['name']];
                return $all;
            }, [])), 'today' => $this->clock->now()->format('Y-m-d'),
                'timezone' => $this->clock->now()->getTimezone()->getName(), 'new_id' => EntityId::generate()->value,
                'windows' => [], 'slots' => []];
            $result['booking_policy'] = $policy->facts();
            $result['capacity_reached'] = $capacity;
            $calendar = null;
            $teaching = null;
            $read = null;
            $source = [];
            if ($windowId !== null) {
                $window = $this->availability->find($windowId) ?? throw new RecordNotFoundException(trans(
                    'Availability not found.'
                ));
                $this->bookable($window, $hosts);
                $source = $window->facts();
                $range = new AppointmentTime(
                    $source['time']['start'],
                    $source['time']['end'],
                    $source['time']['timezone']
                );
                $calendar = $policy->blocksStudentClasses() && !$capacity ? $this->classes->read(
                    $student,
                    $range
                ) : null;
                $teaching = $policy->blocksAdvisorTeaching() && !$capacity ? $this->teaching->read(
                    new EntityId($source['host_person_id']),
                    new EntityId($source['host_user_id']),
                    $range
                ) : null;
                $read = function () use ($window, $student, $policy, $capacity, $calendar, $teaching): array {
                    $slots = [];
                    if ($capacity) {
                        return $slots;
                    }
                    foreach ($window->slots() as $slot) {
                        if (
                            $policy->permits($slot, $this->clock->now())
                            && ($calendar?->permits($slot) ?? true) && ($teaching?->permits($slot) ?? true)
                            && !$this->records->conflict(
                                $student,
                                new EntityId($window->facts()['host_person_id']),
                                new EntityId($window->facts()['id']),
                                $slot
                            )
                        ) {
                            $slots[] = $slot->facts();
                        }
                    }
                    return $slots;
                };
                $result['window'] = array_intersect_key($window->view(), array_flip(['id', 'time',
                    'slot_minutes', 'message', 'version']));
                $result['slots'] = $read();
            } elseif ($host !== null && $date !== null) {
                if (!in_array($host->value, array_column($hosts, 'advisor_person_id'), true)) {
                    throw new AccessDeniedException();
                }
                $period = new CalendarPeriod($date, $this->clock->now()->getTimezone(), 'week');
                $day = array_values(array_filter(
                    $period->dayIntervals(),
                    static fn($d): bool => $d->startsAt->format('Y-m-d') === $date
                ))[0];
                $utc = new DateTimeZone('UTC');
                $read = fn(): array => $this->availability->on(
                    $host,
                    $day->startsAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                    $day->endsAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')
                );
                $windows = $read();
                if (count($windows) > 20) {
                    throw new ValidationException([['field' => 'date',
                        'message' => trans('Ask the advising office to consolidate availability on this date.')]]);
                }
                $source = array_map(static fn(AdvisingAvailability $w): array => $w->facts(), $windows);
                foreach ($windows as $window) {
                    try {
                        $this->bookable($window, $hosts);
                    } catch (AccessDeniedException) {
                        continue;
                    }
                    if ($window->facts()['time']['ends_at'] > $this->now()) {
                        $result['windows'][] = array_intersect_key($window->view(), array_flip(['id', 'time',
                            'slot_minutes', 'message', 'version']));
                    }
                }
            } elseif ($host !== null || $date !== null) {
                throw new ValidationException([['field' => 'date',
                    'message' => trans('Choose an advisor and date together.')]]);
            }
            $this->audit->record($actor, 'advising.available_times_read', $student);
            if ($windowId !== null) {
                if ($this->availability->find($windowId)?->facts() !== $source || $read() !== $result['slots']) {
                    throw new ConcurrentModificationException(trans(
                        'Available times changed. Refresh before booking.'
                    ));
                }
            } elseif (
                $read !== null && $source !== array_map(
                    static fn(AdvisingAvailability $w): array => $w->facts(),
                    $read()
                )
            ) {
                throw new ConcurrentModificationException(trans('Availability changed. Choose the date again.'));
            }
            $this->finish($student, $pursuit, 'owner', $actor, $context);
            $this->policies->assertCurrent($policy);
            if ($calendar !== null) {
                $this->classes->assertCurrent($student, $calendar);
            }
            if ($teaching !== null) {
                $this->teaching->assertCurrent(
                    new EntityId($source['host_person_id']),
                    new EntityId($source['host_user_id']),
                    $teaching
                );
            }
            if ($capacity !== ($this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active'])) {
                throw new ConcurrentModificationException(trans(
                    'Active appointments changed. Refresh available times.'
                ));
            }
            if ($hosts !== $this->advisors->appointmentHosts($student, $pursuit, 'owner')) {
                throw new ConcurrentModificationException(trans('Advisor assignments changed. Refresh appointments.'));
            }
            return $result;
        });
    }

    public function book(
        EntityId $student,
        EntityId $pursuit,
        EntityId $id,
        EntityId $windowId,
        int $windowVersion,
        string $start
    ): array {
        $actor = $this->authorize($student, $pursuit, 'owner');
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $id,
            $windowId,
            $windowVersion,
            $start,
            $actor
): array {
            $this->lockStudent($student, true);
            $old = $this->records->find($student, $pursuit, $id);
            $window = $old === null ? ($this->availability->find($windowId)
                ?? throw new RecordNotFoundException(trans('Availability not found.'))) : null;
            $host = new EntityId($old?->facts()['host_person_id'] ?? $window->facts()['host_person_id']);
            $this->lockParticipants($student, $host);
            $this->authorize($student, $pursuit, 'owner', $actor);
            $context = $this->context($student, $pursuit);
            $old = $this->records->find($student, $pursuit, $id);
            if ($old !== null) {
                $facts = $old->facts();
                if (
                    $facts['owner_user_id'] !== $actor->value || !isset($facts['booking'])
                    || $facts['booking']['window_id'] !== $windowId->value || $windowVersion !== 0
                ) {
                    throw new AccessDeniedException();
                }
                $booking = new AvailabilityBooking($facts['booking']);
                $time = $booking->slot($start) ?? throw new ConcurrentModificationException(trans(
                    'Choose one of the offered times.'
                ));
                $result = $old->retry($id, $actor, $old->intent(
                    'schedule',
                    -1,
                    $time,
                    $facts['actions'][0]['message'],
                    $old->policyFor($id)
                ));
            } else {
                $window = $this->availability->find($windowId) ?? throw new RecordNotFoundException(trans(
                    'Availability not found.'
                ));
                $hosts = $this->advisors->appointmentHosts($student, $pursuit, 'owner');
                $this->bookable($window, $hosts);
                $source = $window->facts();
                if ($windowVersion !== $source['version'] || $host->value !== $source['host_person_id']) {
                    throw new ConcurrentModificationException(trans('Availability changed. Refresh before booking.'));
                }
                $booking = AvailabilityBooking::from($window);
                $time = $booking->slot($start) ?? throw new ConcurrentModificationException(trans(
                    'Choose one of the offered times.'
                ));
                $hosts = $this->eligible($student, $pursuit, 'owner', $context, $host, $time, $id);
                $policy = $this->policies->snapshot();
                $policy->requireTime($time, $this->clock->now());
                $calendar = $policy->blocksStudentClasses() ? $this->classes->read($student, $time) : null;
                $calendar?->requireTime($time);
                $teaching = $policy->blocksAdvisorTeaching() ? $this->teaching->read(
                    $host,
                    new EntityId($source['host_user_id']),
                    $time
                ) : null;
                $teaching?->requireTime($time);
                if ($this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active']) {
                    throw new ConcurrentModificationException(trans(
                        'Resolve existing appointments before booking another under the institution '
                        . 'active-appointment limit.'
                    ));
                }
                $item = AdvisingAppointment::create(
                    $id,
                    $student,
                    $pursuit,
                    $actor,
                    $host,
                    new EntityId($source['host_user_id']),
                    $actor,
                    $time,
                    $source['message'],
                    $this->now(),
                    $booking,
                    $policy
                );
                $this->records->save($item, null);
                $this->audit->record($actor, 'advising.appointment_self_booked', $id);
                $this->verify($item);
                $this->policies->assertCurrent($policy);
                if ($calendar !== null) {
                    $this->classes->assertCurrent($student, $calendar);
                }
                if ($teaching !== null) {
                    $this->teaching->assertCurrent($host, new EntityId($source['host_user_id']), $teaching);
                }
                $policy->requireTime($time, $this->clock->now());
                $this->assertCapacity($student, $pursuit, $policy);
                if (
                    $this->availability->find($windowId)?->facts() !== $source
                    || $hosts !== $this->eligible($student, $pursuit, 'owner', $context, $host, $time, $id)
                ) {
                    throw new ConcurrentModificationException(trans(
                        'Availability changed before booking completed. Refresh it.'
                    ));
                }
                $result = $item->retry($id, $actor, $item->intent('schedule', -1, $time, $source['message'], $policy));
            }
            $this->finish($student, $pursuit, 'owner', $actor, $context);
            return $result;
        });
    }

    /** Minimized chronological calendar; global reads are strictly the signed-in student's own appointments. */
    public function calendar(
        string $channel,
        ?EntityId $student,
        ?EntityId $pursuit,
        ?string $date = null,
        string $view = 'week',
        bool $includeCancelled = false,
    ): array {
        if (($student === null) !== ($pursuit === null) || ($student === null && $channel !== 'owner')) {
            throw new AccessDeniedException();
        }
        $actor = $student === null ? $this->access->authenticatedUserId()
        : $this->authorize($student, $pursuit, $channel);
        $period = new CalendarPeriod(
            $date ?? $this->clock->now()->format('Y-m-d'),
            $this->clock->now()->getTimezone(),
            $view
        );
        return $this->transactions->run(function () use (
            $channel,
            $student,
            $pursuit,
            $actor,
            $period,
            $includeCancelled
        ): array {
            $read = fn(): array => $this->records->period(
                $student,
                $pursuit,
                $channel === 'owner' ? $actor : null,
                $period,
                $includeCancelled
            );
            $records = $read();
            if (count($records) > 100) {
                throw new ValidationException([['field' => 'date', 'message' => trans(
                    'More than 100 advising appointments match. Choose a week or ask the advising office for help.'
                )]]);
            }
            $contexts = $hosts = $programs = $rows = [];
            $budget = new HistoryReadBudget();
            if ($student !== null) {
                $contexts[$pursuit->value] = [$student, $pursuit, $this->context($student, $pursuit, $budget)];
            }
            foreach ($records as $item) {
                $data = $item->facts();
                $s = new EntityId($data['student_id']);
                $p = new EntityId($data['pursuit_id']);
                if (!isset($contexts[$p->value])) {
                    $this->authorize($s, $p, $channel, $actor);
                    $contexts[$p->value] = [$s, $p, $this->context($s, $p, $budget)];
                }
                $assignment = $this->students->assignment($p);
                $programs[$assignment->programId->value] ??= $this->students->program($assignment->programId);
                $program = $programs[$assignment->programId->value];
                if (!isset($hosts[$data['host_person_id']])) {
                    $host = $this->admissions->person(new EntityId($data['host_person_id']));
                    $hosts[$host->id->value] = [$host->givenName, $host->familyName, $host->ownerUserId?->value];
                }
                [$givenName, $familyName] = $hosts[$data['host_person_id']];
                $rows[] = ['id' => $data['id'], 'student_id' => $s->value, 'pursuit_id' => $p->value,
                    'host_name' => $givenName . ' ' . $familyName,
                    'program_code' => $program['code'], 'program_title' => $program['title'],
                    'status' => $data['status'], 'starts_at' => $data['time']['starts_at'],
                    'ends_at' => $data['time']['ends_at']];
            }
            $this->audit->record($actor, 'advising.appointment_calendar_read', $student ?? $actor);
            if (
                array_map(static fn(AdvisingAppointment $i): array => $i->facts(), $records)
                !== array_map(static fn(AdvisingAppointment $i): array => $i->facts(), $read())
            ) {
                throw new ConcurrentModificationException(trans('Appointments changed. Refresh the calendar.'));
            }
            foreach ($contexts as [$s, $p, $context]) {
                $this->finish($s, $p, $channel, $actor, $context, $budget);
            }
            foreach ($hosts as $id => $snapshot) {
                $host = $this->admissions->person(new EntityId($id));
                if ($snapshot !== [$host->givenName, $host->familyName, $host->ownerUserId?->value]) {
                    throw new ConcurrentModificationException(trans('Advisor account changed. Refresh appointments.'));
                }
            }
            foreach ($programs as $id => $program) {
                if ($program !== $this->students->program(new EntityId($id))) {
                    throw new ConcurrentModificationException(trans('Program labels changed. Refresh the calendar.'));
                }
            }
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            if ($student !== null) {
                $this->authorize($student, $pursuit, $channel, $actor);
            }
            return $period->summarize($rows, ['scheduled', 'held', 'no_show', 'cancelled'])
                + ['today' => $this->clock->now()->format('Y-m-d'), 'include_cancelled' => $includeCancelled];
        });
    }

    public function schedule(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        EntityId $id,
        EntityId $host,
        string $start,
        string $end,
        string $timezone,
        #[\SensitiveParameter] string $message,
    ): array {
        if ($channel === 'owner') {
            throw new AccessDeniedException();
        }
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $id,
            $host,
            $start,
            $end,
            $timezone,
            $message,
            $actor
        ): array {
            $this->lock($student, $host);
            $this->authorize($student, $pursuit, $channel, $actor);
            $context = $this->context($student, $pursuit);
            $time = new AppointmentTime($start, $end, $timezone);
            $old = $this->records->find($student, $pursuit, $id);
            if ($old !== null) {
                if (
                    $old->facts()['host_person_id'] !== $host->value
                    || $old->facts()['owner_user_id'] !== $context['owner']
                ) {
                    throw new AccessDeniedException();
                }
                $result = $old->retry($id, $actor, $old->intent('schedule', -1, $time, $message, $old->policyFor($id)));
            } else {
                $hosts = $this->eligible($student, $pursuit, $channel, $context, $host, $time);
                $policy = $this->policies->snapshot();
                $policy->requireTime($time, $this->clock->now());
                $calendar = $policy->blocksStudentClasses() ? $this->classes->read($student, $time) : null;
                $calendar?->requireTime($time);
                $hostUser = $this->admissions->person($host)->ownerUserId ?? throw new AccessDeniedException();
                $teaching = $policy->blocksAdvisorTeaching() ? $this->teaching->read($host, $hostUser, $time) : null;
                $teaching?->requireTime($time);
                if ($this->records->activeCount($student, $pursuit) >= $policy->rules['maximum_active']) {
                    throw new ConcurrentModificationException(trans(
                        'Resolve existing appointments before scheduling another under the institution '
                        . 'active-appointment limit.'
                    ));
                }
                $item = AdvisingAppointment::create(
                    $id,
                    $student,
                    $pursuit,
                    new EntityId($context['owner']),
                    $host,
                    $hostUser,
                    $actor,
                    $time,
                    $message,
                    $this->now(),
                    policy: $policy
                );
                $this->records->save($item, null);
                $this->audit->record($actor, 'advising.appointment_scheduled', $id);
                $this->verify($item);
                $this->policies->assertCurrent($policy);
                if ($calendar !== null) {
                    $this->classes->assertCurrent($student, $calendar);
                }
                if ($teaching !== null) {
                    $this->teaching->assertCurrent($host, $hostUser, $teaching);
                }
                $policy->requireTime($time, $this->clock->now());
                $this->assertCapacity($student, $pursuit, $policy);
                if ($hosts !== $this->eligible($student, $pursuit, $channel, $context, $host, $time, $id)) {
                    throw new ConcurrentModificationException(trans(
                        'Advisor assignments changed. Refresh appointments.'
                    ));
                }
                $result = $item->retry($id, $actor, $item->intent('schedule', -1, $time, $message, $policy));
            }
            $this->finish($student, $pursuit, $channel, $actor, $context);
            return $result;
        });
    }

    public function act(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        EntityId $id,
        EntityId $operation,
        string $action,
        int $version,
        ?AppointmentTime $time,
        #[\SensitiveParameter] string $message,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        $correction = in_array($action, ['correct_held', 'correct_no_show'], true);
        if ($correction && !$this->canCorrect($channel, $actor)) {
            throw new AccessDeniedException();
        }
        if (($channel === 'owner' && $action !== 'cancel') || $action === 'schedule') {
            throw new AccessDeniedException();
        }
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $id,
            $operation,
            $action,
            $version,
            $time,
            $message,
            $actor,
            $correction,
        ): array {
            $this->lockStudent($student, $time !== null);
            $old = $this->records->find($student, $pursuit, $id)
                ?? throw new RecordNotFoundException(trans('Advising appointment not found.'));
            $host = new EntityId($old->facts()['host_person_id']);
            $this->lockParticipants($student, $host);
            $this->authorize($student, $pursuit, $channel, $actor);
            if ($correction && !$this->canCorrect($channel, $actor)) {
                throw new AccessDeniedException();
            }
            $item = $this->records->find($student, $pursuit, $id)
                ?? throw new RecordNotFoundException(trans('Advising appointment not found.'));
            $context = $this->context($student, $pursuit);
            $facts = $item->facts();
            $hostOwner = $this->admissions->person($host)->ownerUserId?->value;
            if (
                ($channel === 'owner' && $facts['owner_user_id'] !== $actor->value)
                || ($channel === 'advisor' && $facts['host_user_id'] !== $actor->value)
                || ($facts['owner_user_id'] !== $context['owner'] && ($channel !== 'staff' || $action !== 'cancel'))
                || ($facts['host_user_id'] !== $hostOwner && $action !== 'cancel')
            ) {
                throw new AccessDeniedException();
            }
            $accepted = in_array($operation->value, array_column($facts['actions'], 'operation_id'), true);
            $policy = $time === null ? null : ($accepted ? $item->policyFor($operation) : $this->policies->snapshot());
            $intent = $item->intent($action, $version, $time, $message, $policy);
            if (($result = $item->retry($operation, $actor, $intent)) === null) {
                if ($channel === 'owner' && $this->now() >= $facts['time']['starts_at']) {
                    throw new AccessDeniedException(trans('Contact the advising office to change a past appointment.'));
                }
                $hosts = $time === null ? null
                    : $this->eligible($student, $pursuit, $channel, $context, $host, $time, $id);
                $calendar = $time !== null && $policy?->blocksStudentClasses()
                    ? $this->classes->read($student, $time) : null;
                $calendar?->requireTime($time);
                $teaching = $time !== null && $policy?->blocksAdvisorTeaching()
                    ? $this->teaching->read($host, new EntityId($facts['host_user_id']), $time) : null;
                $teaching?->requireTime($time);
                $policy?->requireTime($time, $this->clock->now());
                $result = $item->apply($operation, $actor, $action, $version, $time, $message, $this->now(), $policy);
                $this->records->save($item, $version);
                $this->audit->record($actor, 'advising.appointment_' . $action, $id);
                $this->verify($item);
                if ($calendar !== null) {
                    $this->classes->assertCurrent($student, $calendar);
                }
                if ($teaching !== null) {
                    $this->teaching->assertCurrent($host, new EntityId($facts['host_user_id']), $teaching);
                }
                if ($policy !== null) {
                    $this->policies->assertCurrent($policy);
                    $policy->requireTime($time, $this->clock->now());
                }
                if (
                    $time !== null && $hosts !== $this->eligible(
                        $student,
                        $pursuit,
                        $channel,
                        $context,
                        $host,
                        $time,
                        $id
                    )
                ) {
                    throw new ConcurrentModificationException(trans(
                        'Advisor assignments changed. Refresh appointments.'
                    ));
                }
            }
            if ($hostOwner !== $this->admissions->person($host)->ownerUserId?->value) {
                throw new ConcurrentModificationException(trans('Advisor account changed. Refresh appointments.'));
            }
            if ($correction && !$this->canCorrect($channel, $actor)) {
                throw new AccessDeniedException();
            }
            $this->finish($student, $pursuit, $channel, $actor, $context);
            return $result;
        });
    }

    private function eligible(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        array $context,
        EntityId $host,
        AppointmentTime $time,
        ?EntityId $except = null,
    ): array {
        if (
            !$context['current'] || $context['owner'] === null
            || $time->timezone !== $this->clock->now()->getTimezone()->getName()
        ) {
            throw new AccessDeniedException(trans('Use the current primary program and institution timezone.'));
        }
        $time->requireFuture($this->clock->now());
        $hosts = $this->advisors->appointmentHosts($student, $pursuit, $channel);
        $date = substr($time->start, 0, 10);
        $found = array_filter($hosts, static fn(array $h): bool => $h['advisor_person_id'] === $host->value
            && $h['starts_on'] <= $date && ($h['ends_on'] === null || $h['ends_on'] >= $date));
        if ($found === [] || $this->records->conflict($student, $host, $except ?? EntityId::generate(), $time)) {
            throw new ConcurrentModificationException(trans(
                'The advisor must be assigned on that date, and neither participant may have '
                    . 'an overlapping advising appointment.'
            ));
        }
        return $hosts;
    }

    private function assertCapacity(
        EntityId $student,
        EntityId $pursuit,
        \Domain\Advising\BookingPolicySnapshot $policy
    ): void {
        if ($this->records->activeCount($student, $pursuit) > $policy->rules['maximum_active']) {
            throw new ConcurrentModificationException(trans(
                'Active appointments changed before completion. Refresh them.'
            ));
        }
    }

    private function lock(EntityId $student, EntityId $host): void
    {
        $this->lockStudent($student, true);
        $this->lockParticipants($student, $host);
    }

    private function lockStudent(EntityId $student, bool $newTime = false): void
    {
        $this->roles->lock();
        $this->students->lock($student);
        if ($newTime) {
            $this->classes->lock();
        }
    }

    private function lockParticipants(EntityId $student, EntityId $host): void
    {
        $ids = array_unique([$this->admissions->student($student)->personId->value, $host->value]);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->admissions->lockPerson(new EntityId($id));
        }
    }

    private function verify(AdvisingAppointment $item): void
    {
        $data = $item->facts();
        if (
            $this->records->find(
                new EntityId($data['student_id']),
                new EntityId($data['pursuit_id']),
                new EntityId($data['id'])
            )?->facts() !== $data
        ) {
            throw new ConcurrentModificationException(trans(
                'The appointment changed before completion. Refresh appointments.'
            ));
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
