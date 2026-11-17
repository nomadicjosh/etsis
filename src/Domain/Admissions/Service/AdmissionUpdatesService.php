<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeImmutable;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class AdmissionUpdatesService
{
    public function __construct(
        private AdmissionUpdatesRepository $updates,
        private PersonAccountRepository $people,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }
    /** @return array<string,mixed> */
    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException('Use the complete admission updates cursor.');
        }
        if ($afterAt !== null) {
            if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D', $afterAt) !== 1) {
                throw new InvalidArgumentException('Use a valid admission updates cursor.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $afterAt);
            if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $afterAt) {
                throw new InvalidArgumentException('Use a valid admission updates cursor.');
            }
        }
        $rows = $this->updates->page($actor, $unreadOnly, $afterAt, $after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = [];
        foreach ($rows as $row) {
            $items[] = ['id' => $row['id'], 'application_id' => $row['application_id'],
                'revision' => (int) $row['revision'], 'unread' => (int) $row['read_revision'] < (int) $row['revision'],
                'updated_at' => $row['updated_at'], 'program_code' => $row['program_code'],
                'program_title' => $row['program_title'], 'term_code' => $row['term_code']];
        }
        $count = $this->updates->unreadCount($actor);
        $this->person($actor);
        return ['items' => $items, 'unread_count' => $count, 'next' => $more
            ? ['at' => end($rows)['updated_at'], 'id' => end($rows)['id']] : null];
    }
    private function person(EntityId $actor): \Domain\Person\Person
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value
            || $person?->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException('The current account needs its own person record.');
        }
        return $person;
    }
    /** @return array{unread:bool} */
    public function read(EntityId $notice, int $revision): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if ($revision < 1 || $revision > 2000000000) {
            throw new InvalidArgumentException('Reload admission updates before marking one read.');
        }
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision): array {
            $this->admissions->lockPerson($person->id);
            $this->person($actor);
            $owned = $this->updates->owned($actor, $notice)
                ?? throw new AccessDeniedException('This account does not own the admission update.');
            $this->updates->lock($notice);
            $owned = $this->updates->owned($actor, $notice)
                ?? throw new AccessDeniedException('This account does not own the admission update.');
            if ($revision > (int) $owned['revision']) {
                throw new ConcurrentModificationException('Reload the latest admission update.');
            }
            if ($revision > (int) $owned['read_revision']) {
                $this->updates->markRead($actor, $notice, $revision);
                $this->audit->record($actor, 'admissions.update_read', $notice);
            }
            $this->person($actor);
            return ['unread' => max($revision, (int) $owned['read_revision']) < (int) $owned['revision']];
        });
    }
}
