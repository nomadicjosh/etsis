<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use DateTimeImmutable;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AdvisingAvailabilitySeries;
use Domain\Advising\AvailabilityPattern;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisorRepository;
use Domain\Advising\Repository\AdvisingAvailabilityRepository;
use Domain\Advising\Repository\AdvisingAvailabilitySeriesRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
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
        private AdvisingAvailabilitySeriesRepository $seriesRecords,
        private ReviewProof $proofs,
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
            return ['items' => array_map($this->windowView(...), $items),
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

    private function windowView(AdvisingAvailability $item): array
    {
        return $item->view() + ['expired' => $item->facts()['time']['ends_at'] <= $this->now()];
    }

    private function seriesScope(
        EntityId $host,
        string $channel,
        EntityId $id,
        EntityId $actor
    ): AdvisingAvailabilitySeries {
        $this->authorize($host, $channel, $actor);
        $series = $this->seriesRecords->find($id) ?? throw new RecordNotFoundException(trans(
            'Recurring availability not found.'
        ));
        if (
            $series->facts()['host_person_id'] !== $host->value
            || ($channel === 'advisor' && $series->facts()['host_user_id'] !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $series;
    }

    /** Verify every member against its original offer, allowing genuine individual closures. */
    private function members(AdvisingAvailabilitySeries $series): array
    {
        $data = $series->facts();
        $items = [];
        foreach ($data['windows'] as $window) {
            $item = $this->records->find(new EntityId($window['id'])) ?? throw new CorruptHistoryException();
            $d = $item->facts();
            if (
                $d['host_person_id'] !== $data['host_person_id'] || $d['host_user_id'] !== $data['host_user_id']
                || ($d['series_id'] ?? null) !== $data['id'] || $d['time'] !== $window['time']
                || $d['slot_minutes'] !== $data['slot_minutes'] || $d['message'] !== $data['message']
                || $d['created_at'] !== $data['created_at']
                || $d['receipts']['create']['actor_id'] !== $data['receipts']['create']['actor_id']
            ) {
                throw new CorruptHistoryException();
            }
            if (
                in_array($d['id'], $data['closed_ids'], true)
                && ($d['status'] !== 'closed' || $d['closed_at'] !== $data['closed_at']
                    || $d['close_reason'] !== $data['close_reason']
                    || $d['receipts']['close']['actor_id'] !== $data['receipts']['close']['actor_id'])
            ) {
                throw new CorruptHistoryException();
            }
            $items[] = $item;
        }
        return $items;
    }

    private function seriesVerify(AdvisingAvailabilitySeries $series, array $members): void
    {
        if (
            $this->seriesRecords->find(new EntityId($series->facts()['id']))?->facts() !== $series->facts()
            || array_map(static fn(AdvisingAvailability $i): array => $i->facts(), $this->members($series))
                !== array_map(static fn(AdvisingAvailability $i): array => $i->facts(), $members)
        ) {
            throw new ConcurrentModificationException(trans(
                'Recurring availability changed before completion. Review it again.'
            ));
        }
    }

    private function remaining(array $members): array
    {
        $now = $this->now();
        return array_values(array_map(
            static fn(AdvisingAvailability $item): string => $item->facts()['id'],
            array_filter($members, static fn(AdvisingAvailability $item): bool => $item->facts()['status'] === 'open'
            && $item->facts()['time']['ends_at'] > $now)
        ));
    }

    public function seriesList(EntityId $host, string $channel, ?EntityId $after = null): array
    {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $after, $actor): array {
            $subject = $this->subject($host);
            $rows = $this->seriesRecords->page($host, $channel === 'advisor' ? $actor : null, $after);
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, 'advising.recurring_availability_read', $host);
            foreach ($items as $item) {
                if ($this->seriesRecords->find(new EntityId($item->facts()['id']))?->facts() !== $item->facts()) {
                    throw new ConcurrentModificationException();
                }
            }
            $this->finish($host, $channel, $actor, $subject);
            return ['items' => array_map(static fn(AdvisingAvailabilitySeries $s): array => [
                'id' => $s->facts()['id'], 'from' => $s->facts()['pattern']['from'], 'through' =>
                    $s->facts()['pattern']['through'],
                'status' => $s->facts()['status'], 'count' => count($s->facts()['windows'])], $items),
                'next_after' => count($rows) > 10 ? end($items)->facts()['id'] : null,
                'can_create' => $subject['eligible'] && $subject['account'] !== null];
        });
    }

    public function seriesView(EntityId $host, string $channel, EntityId $id): array
    {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $id, $actor): array {
            $subject = $this->subject($host);
            $series = $this->seriesScope($host, $channel, $id, $actor);
            $members = $this->members($series);
            $this->audit->record($actor, 'advising.recurring_offer_read', $id);
            $this->seriesVerify($series, $members);
            $this->finish($host, $channel, $actor, $subject);
            return ['series' => $series->view(), 'windows' => array_map($this->windowView(...), $members),
                'remaining_ids' => $series->facts()['status'] === 'open' ? $this->remaining($members) : []];
        });
    }

    private function checkPlan(EntityId $host, array $subject, AdvisingAvailabilitySeries $series, bool $stored): void
    {
        $d = $series->facts();
        if (!$subject['eligible'] || $subject['account'] !== $d['host_user_id']) {
            throw new AccessDeniedException();
        }
        if ($d['pattern']['timezone'] !== $this->clock->now()->getTimezone()->getName()) {
            throw new ConcurrentModificationException(trans(
                'The institution timezone changed. Review the pattern again.'
            ));
        }
        if ($this->records->activeCount($host, $this->now()) + ($stored ? 0 : count($d['windows'])) > 100) {
            throw new ConcurrentModificationException(trans(
                'Use at most 100 upcoming availability windows per advisor.'
            ));
        }
        foreach ($d['windows'] as $window) {
            $t = $window['time'];
            $time = new AppointmentTime($t['start'], $t['end'], $t['timezone']);
            $time->requireFuture($this->clock->now());
            if (
                $this->records->conflict($host, $time, $stored ? new EntityId($window['id']) : null)
                || $this->dayCount($host, $time) + ($stored ? 0 : 1) > 20
            ) {
                throw new ConcurrentModificationException(trans(
                    'A recurring window conflicts or exceeds the daily limit. Review the pattern again.'
                ));
            }
        }
    }

    public function reviewSeries(
        EntityId $host,
        string $channel,
        AvailabilityPattern $pattern,
        int $minutes,
        #[\SensitiveParameter] string $message
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $pattern, $minutes, $message, $actor): array {
            $subject = $this->subject($host);
            if (!$subject['eligible'] || $subject['account'] === null) {
                throw new AccessDeniedException();
            }
            $id = EntityId::generate();
            $ids = array_map(static fn(): string => EntityId::generate()->value, $pattern->times($minutes));
            $series = AdvisingAvailabilitySeries::create(
                $id,
                $host,
                new EntityId($subject['account']),
                $actor,
                $channel,
                $pattern,
                $minutes,
                $message,
                $ids,
                $this->now()
            );
            $this->checkPlan($host, $subject, $series, false);
            $this->audit->record($actor, 'advising.recurring_offer_reviewed', $host);
            $this->checkPlan($host, $subject, $series, false);
            $this->finish($host, $channel, $actor, $subject);
            return ['series_id' => $id->value, 'pattern' => $pattern->facts(), 'slot_minutes' => $minutes,
                'message' => $series->facts()['message'], 'window_ids' => $ids, 'windows' =>
                    $series->facts()['windows'],
                'revision' => $series->creationIntent(), 'review_token' => $this->proofs->issue(
                    'advising.availability_series',
                    'create:' . $channel,
                    $host,
                    $actor,
                    $id,
                    $series->creationIntent()
                )];
        });
    }

    public function createSeries(
        EntityId $host,
        string $channel,
        EntityId $id,
        AvailabilityPattern $pattern,
        int $minutes,
        #[\SensitiveParameter] string $message,
        array $ids,
        string $revision,
        #[\SensitiveParameter] string $token
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use (
            $host,
            $channel,
            $id,
            $pattern,
            $minutes,
            $message,
            $ids,
            $revision,
            $token,
            $actor
): array {
            $this->roles->lock();
            $this->admissions->lockPerson($host);
            $this->authorize($host, $channel, $actor);
            $subject = $this->subject($host);
            $old = $this->seriesRecords->find($id);
            if ($old !== null) {
                $this->seriesScope($host, $channel, $id, $actor);
            }
            $account = $old?->facts()['host_user_id'] ?? $subject['account'];
            if ($account === null) {
                throw new AccessDeniedException();
            }
            $series = AdvisingAvailabilitySeries::create(
                $id,
                $host,
                new EntityId($account),
                $actor,
                $channel,
                $pattern,
                $minutes,
                $message,
                $ids,
                $old?->facts()['created_at'] ?? $this->now()
            );
            if (!hash_equals($series->creationIntent(), $revision)) {
                throw new ConcurrentModificationException(trans(
                    'The recurring offer changed after review. Review it again.'
                ));
            }
            $this->proofs->validate(
                $token,
                'advising.availability_series',
                'create:' . $channel,
                $host,
                $actor,
                $id,
                $revision,
                $old !== null
            );
            if ($old !== null) {
                $result = $old->retry('create', $actor, $series->creationIntent());
                $this->seriesVerify($old, $this->members($old));
            } else {
                $this->checkPlan($host, $subject, $series, false);
                $this->seriesRecords->save($series, null);
                foreach ($series->facts()['windows'] as $window) {
                    $t = $window['time'];
                    $item = AdvisingAvailability::create(
                        new EntityId($window['id']),
                        $host,
                        new EntityId($account),
                        $actor,
                        new AppointmentTime($t['start'], $t['end'], $t['timezone']),
                        $minutes,
                        $series->facts()['message'],
                        $series->facts()['created_at'],
                        $id
                    );
                    $this->records->save($item, null);
                }
                $members = $this->members($series);
                $this->audit->record($actor, 'advising.recurring_availability_created', $id);
                $this->seriesVerify($series, $members);
                $this->checkPlan($host, $subject, $series, true);
                $result = $series->retry('create', $actor, $series->creationIntent());
            }
            $this->finish($host, $channel, $actor, $subject);
            $this->proofs->validate(
                $token,
                'advising.availability_series',
                'create:' . $channel,
                $host,
                $actor,
                $id,
                $revision,
                $old !== null
            );
            return $result;
        });
    }

    public function reviewSeriesClose(
        EntityId $host,
        string $channel,
        EntityId $id,
        #[\SensitiveParameter] string $reason
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use ($host, $channel, $id, $reason, $actor): array {
            $subject = $this->subject($host);
            $series = $this->seriesScope($host, $channel, $id, $actor);
            $members = $this->members($series);
            $ids = $this->remaining($members);
            $copy = AdvisingAvailabilitySeries::restore($series->facts());
            $copy->close($actor, $series->facts()['version'], $ids, $reason, $this->now());
            $revision = AdvisingAvailabilitySeries::digest([$series->facts(), array_map(static fn(
                AdvisingAvailability $i): array => $i->facts(), $members),
                $copy->closureIntent(0, $ids, $reason)]);
            $this->audit->record($actor, 'advising.recurring_closure_reviewed', $id);
            $this->seriesVerify($series, $members);
            if ($ids !== $this->remaining($members)) {
                throw new ConcurrentModificationException();
            }
            $this->finish($host, $channel, $actor, $subject);
            return ['series_id' => $id->value, 'version' => $series->facts()['version'], 'window_ids' => $ids,
                'reason' => AdvisingAppointment::message($reason), 'revision' => $revision,
                'windows' => array_values(array_map(
                    static fn(AdvisingAvailability $i): array => $i->view(),
                    array_filter($members, static fn(AdvisingAvailability $i): bool => in_array(
                        $i->facts()['id'],
                        $ids,
                        true
                    ))
                )),
                'review_token' => $this->proofs->issue('advising.availability_series', 'close:'
                    . $channel, $host, $actor, $id, $revision)];
        });
    }

    public function closeSeries(
        EntityId $host,
        string $channel,
        EntityId $id,
        int $version,
        array $ids,
        #[\SensitiveParameter] string $reason,
        string $revision,
        #[\SensitiveParameter] string $token
    ): array {
        $actor = $this->authorize($host, $channel);
        return $this->transactions->run(function () use (
            $host,
            $channel,
            $id,
            $version,
            $ids,
            $reason,
            $revision,
            $token,
            $actor
): array {
            $this->roles->lock();
            $this->admissions->lockPerson($host);
            $subject = $this->subject($host);
            $series = $this->seriesScope($host, $channel, $id, $actor);
            $members = $this->members($series);
            $result = $series->retry('close', $actor, $series->closureIntent($version, $ids, $reason));
            $accepted = $result !== null;
            $this->proofs->validate(
                $token,
                'advising.availability_series',
                'close:' . $channel,
                $host,
                $actor,
                $id,
                $revision,
                $result !== null
            );
            if ($result === null) {
                $expected = AdvisingAvailabilitySeries::digest([$series->facts(), array_map(static fn(
                    AdvisingAvailability $i): array => $i->facts(), $members),
                    $series->closureIntent($version, $ids, $reason)]);
                if (!hash_equals($expected, $revision) || $ids !== $this->remaining($members)) {
                    throw new ConcurrentModificationException(trans(
                        'Remaining availability changed after review. Review closure again.'
                    ));
                }
                $at = $this->now();
                $result = $series->close($actor, $version, $ids, $reason, $at);
                foreach ($members as $item) {
                    if (in_array($item->facts()['id'], $ids, true)) {
                        $item->close($actor, 0, $reason, $at);
                        $this->records->save($item, 0);
                    }
                }
                $this->seriesRecords->save($series, $version);
                $this->audit->record($actor, 'advising.recurring_availability_closed', $id);
                foreach ($members as $item) {
                    if (
                        in_array($item->facts()['id'], $ids, true) && $item->facts(
                        )['time']['ends_at'] <= $this->now()
                    ) {
                        throw new ConcurrentModificationException(trans(
                            'A listed window expired. Review remaining-window closure again.'
                        ));
                    }
                }
            }
            $this->seriesVerify($series, $members);
            $this->finish($host, $channel, $actor, $subject);
            $this->proofs->validate(
                $token,
                'advising.availability_series',
                'close:' . $channel,
                $host,
                $actor,
                $id,
                $revision,
                $accepted
            );
            return $result;
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
