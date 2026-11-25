<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use DateTimeImmutable;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisorRepository;
use Domain\Advising\Repository\AdvisingAvailabilityRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class AdvisingAvailabilityService
{
    public function __construct(
        private AdvisingAvailabilityRepository $records,
        private AdvisorRepository $advisors,
        private AdmissionsRepository $admissions,
        private PersonAccountRepository $people,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function authorize(EntityId $host, string $channel, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(AdvisingAppointmentService::PERMISSION);
        if ($channel === 'staff') {
            if ($this->access->requirePermission('sis:student:read')->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        } elseif (
            $channel !== 'advisor' || $this->people->findByUser($actor)?->id->value !== $host->value
            || !$this->advisors->candidate($host)
        ) {
            throw new AccessDeniedException();
        }
        if (
            ($expected !== null && $expected->value !== $actor->value)
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    public function mine(): EntityId
    {
        $actor = $this->access->requirePermission(AdvisingAppointmentService::PERMISSION);
        $person = $this->people->findByUser($actor) ?? throw new AccessDeniedException();
        $this->authorize($person->id, 'advisor', $actor);
        return $person->id;
    }

    public function canManage(): bool
    {
        $actor = $this->access->authenticatedUserId();
        try {
            $this->mine();
            return $actor->value === $this->access->authenticatedUserId()->value;
        } catch (AccessDeniedException) {
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            return false;
        }
    }

    public function candidates(string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission(AdvisingAppointmentService::PERMISSION);
        $this->access->requirePermission('sis:student:read');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80 || preg_match(
                '/[\x00-\x1f\x7f]/',
                $search
            )
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => trans('Enter an advisor search of up to 80 characters.')]]);
        }
        $result = $this->advisors->candidates(trim($search), $after, includeAvailabilityHosts: true);
        if (
            $this->access->requirePermission('sis:student:read')->value !== $actor->value
            || $this->access->requirePermission(AdvisingAppointmentService::PERMISSION)->value !== $actor->value
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $result;
    }

    private function subject(EntityId $host): array
    {
        $p = $this->admissions->person($host);
        return ['name' => $p->givenName . ' ' . $p->familyName, 'account' => $p->ownerUserId?->value,
            'eligible' => $this->advisors->candidate($host)];
    }

    private function finish(EntityId $host, string $channel, EntityId $actor, array $subject): void
    {
        $this->authorize($host, $channel, $actor);
        if ($subject !== $this->subject($host)) {
            throw new ConcurrentModificationException(trans('Advisor context changed. Refresh availability.'));
        }
    }

    private function verify(AdvisingAvailability $item): void
    {
        if ($this->records->find(new EntityId($item->facts()['id']))?->facts() !== $item->facts()) {
            throw new ConcurrentModificationException(trans('Availability changed before completion. Refresh it.'));
        }
    }

    private function dayCount(EntityId $host, AppointmentTime $time): int
    {
        $day = new DateTimeImmutable(substr($time->start, 0, 10), new DateTimeZone($time->timezone));
        $utc = new DateTimeZone('UTC');
        return count($this->records->on(
            $host,
            $day->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')
        ));
    }

    public function listing(EntityId $host, string $channel, bool $finished = false, ?EntityId $after = null): array
    {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $finished, $after, $actor): array {
            $subject = $this->subject($host);
            $rows = $this->records->page(
                $host,
                $channel === 'advisor' ? $actor : null,
                $this->now(),
                $finished,
                $after
            );
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, 'advising.availability_read', $host);
            foreach ($items as $item) {
                $this->verify($item);
            }
            $this->finish($host, $channel, $actor, $subject);
            return ['items' => array_map(static fn(AdvisingAvailability $item): array => $item->view(), $items),
                'next_after' => count($rows) > 10 ? end($items)->facts()['id'] : null,
                'new_id' => EntityId::generate()->value, 'host_name' => $subject['name'],
                'can_create' => $subject['eligible'] && $subject['account'] !== null,
                'timezone' => $this->clock->now()->getTimezone()->getName()];
        });
    }

    public function create(
        EntityId $host,
        string $channel,
        EntityId $id,
        string $start,
        string $end,
        string $timezone,
        int $minutes,
        #[\SensitiveParameter] string $message
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use (
            $host,
            $channel,
            $id,
            $start,
            $end,
            $timezone,
            $minutes,
            $message,
            $actor
): array {
            $this->roles->lock();
            $this->admissions->lockPerson($host);
            $this->authorize($host, $channel, $actor);
            $subject = $this->subject($host);
            $time = new AppointmentTime($start, $end, $timezone);
            $old = $this->records->find($id);
            if ($old !== null) {
                if ($old->facts()['host_person_id'] !== $host->value) {
                    throw new AccessDeniedException();
                }
                $result = $old->retry('create', $actor, $old->creationIntent($time, $minutes, $message));
            } else {
                if (
                    !$subject['eligible'] || $subject['account'] === null
                    || $timezone !== $this->clock->now()->getTimezone()->getName()
                ) {
                    throw new AccessDeniedException(trans('Use an eligible advisor and the institution timezone.'));
                }
                if (
                    $this->records->activeCount($host, $this->now()) >= 100 || $this->records->conflict($host, $time)
                    || $this->dayCount($host, $time) >= 20
                ) {
                    throw new ConcurrentModificationException(trans(
                        'Use non-overlapping availability with at most 100 upcoming windows and 20 per day.'
                    ));
                }
                $item = AdvisingAvailability::create(
                    $id,
                    $host,
                    new EntityId($subject['account']),
                    $actor,
                    $time,
                    $minutes,
                    $message,
                    $this->now()
                );
                $this->records->save($item, null);
                $this->audit->record($actor, 'advising.availability_created', $id);
                $this->verify($item);
                $time->requireFuture($this->clock->now());
                if (
                    $this->records->conflict($host, $time, $id)
                    || $this->records->activeCount($host, $this->now()) > 100
                    || $this->dayCount($host, $time) > 20
                ) {
                    throw new ConcurrentModificationException(trans(
                        'Availability changed before completion. Refresh it.'
                    ));
                }
                $result = $item->retry('create', $actor, $item->creationIntent($time, $minutes, $message));
            }
            $this->finish($host, $channel, $actor, $subject);
            return $result;
        });
    }

    public function close(
        EntityId $host,
        string $channel,
        EntityId $id,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $id, $version, $reason, $actor): array {
            $this->roles->lock();
            $this->admissions->lockPerson($host);
            $this->authorize($host, $channel, $actor);
            $subject = $this->subject($host);
            $item = $this->records->find($id) ?? throw new RecordNotFoundException(trans('Availability not found.'));
            if (
                $item->facts()['host_person_id'] !== $host->value
                || ($channel === 'advisor' && $item->facts()['host_user_id'] !== $actor->value)
            ) {
                throw new AccessDeniedException();
            }
            $before = $item->facts();
            $result = $item->close($actor, $version, $reason, $this->now());
            if ($before !== $item->facts()) {
                $this->records->save($item, $version);
                $this->audit->record($actor, 'advising.availability_closed', $id);
            }
            $this->verify($item);
            $this->finish($host, $channel, $actor, $subject);
            return $result;
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
