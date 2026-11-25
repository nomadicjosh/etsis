<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisingAppointmentRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
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
    ) {
    }

    private function context(EntityId $student, EntityId $pursuit): array
    {
        $s = $this->admissions->student($student);
        $p = $this->students->assignment($pursuit);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException(trans('Student program not found.'));
        }
        $budget = new HistoryReadBudget();
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
            if ($this->context($student, $pursuit)['owner'] !== $actor->value) {
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
    ): void {
        $this->authorize($student, $pursuit, $channel, $actor);
        if ($context !== $this->context($student, $pursuit)) {
            throw new ConcurrentModificationException(trans('Student context changed. Refresh appointments.'));
        }
    }

    public function listing(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        bool $finished = false,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $finished,
            $after,
            $actor
): array {
            $context = $this->context($student, $pursuit);
            $hosts = $channel !== 'owner' && $context['current']
                ? $this->advisors->appointmentHosts($student, $pursuit, $channel) : [];
            $rows = $this->records->page($student, $pursuit, $channel === 'owner' ? $actor : null, $finished, $after);
            $items = array_slice($rows, 0, 10);
            $views = [];
            $hostSnapshots = [];
            foreach ($items as $item) {
                $data = $item->facts();
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
            if ($hosts !== [] && $hosts !== $this->advisors->appointmentHosts($student, $pursuit, $channel)) {
                throw new ConcurrentModificationException(trans('Advisor assignments changed. Refresh appointments.'));
            }
            return ['items' => $views, 'next_after' => count($rows) > 10 ? end($items)->facts()['id'] : null,
                'new_id' => EntityId::generate()->value, 'can_schedule' => $hosts !== [] && $context['owner'] !== null,
                'hosts' => array_map(static fn(array $h): array => array_intersect_key($h, array_flip([
                    'advisor_person_id', 'name', 'starts_on', 'ends_on',
                ])), $hosts), 'timezone' => $this->clock->now()->getTimezone()->getName(), 'now' => $this->now()];
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
                $result = $old->retry($id, $actor, $old->intent('schedule', -1, $time, $message));
            } else {
                $hosts = $this->eligible($student, $pursuit, $channel, $context, $host, $time);
                if ($this->records->activeCount($student, $pursuit) >= 20) {
                    throw new ConcurrentModificationException(trans(
                        'Resolve existing appointments before scheduling another (20 active maximum per program).'
                    ));
                }
                $hostUser = $this->admissions->person($host)->ownerUserId ?? throw new AccessDeniedException();
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
                    $this->now()
                );
                $this->records->save($item, null);
                $this->audit->record($actor, 'advising.appointment_scheduled', $id);
                $this->verify($item);
                if ($hosts !== $this->eligible($student, $pursuit, $channel, $context, $host, $time, $id)) {
                    throw new ConcurrentModificationException(trans(
                        'Advisor assignments changed. Refresh appointments.'
                    ));
                }
                $result = $item->retry($id, $actor, $item->intent('schedule', -1, $time, $message));
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
            $actor
        ): array {
            $old = $this->records->find($student, $pursuit, $id)
                ?? throw new RecordNotFoundException(trans('Advising appointment not found.'));
            $host = new EntityId($old->facts()['host_person_id']);
            $this->lock($student, $host);
            $this->authorize($student, $pursuit, $channel, $actor);
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
            $intent = $item->intent($action, $version, $time, $message);
            if (($result = $item->retry($operation, $actor, $intent)) === null) {
                if ($channel === 'owner' && $this->now() >= $facts['time']['starts_at']) {
                    throw new AccessDeniedException(trans('Contact the advising office to change a past appointment.'));
                }
                $hosts = $time === null ? null
                    : $this->eligible($student, $pursuit, $channel, $context, $host, $time, $id);
                $result = $item->apply($operation, $actor, $action, $version, $time, $message, $this->now());
                $this->records->save($item, $version);
                $this->audit->record($actor, 'advising.appointment_' . $action, $id);
                $this->verify($item);
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

    private function lock(EntityId $student, EntityId $host): void
    {
        $this->roles->lock();
        $this->students->lock($student);
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
