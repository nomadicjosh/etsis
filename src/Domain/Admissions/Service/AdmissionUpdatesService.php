<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Person\Person;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

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
        $person = $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException(trans_html('Use the complete admission updates cursor.'));
        }
        if ($afterAt !== null) {
            if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D', $afterAt) !== 1) {
                throw new InvalidArgumentException(trans_html('Use a valid admission updates cursor.'));
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $afterAt, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $afterAt) {
                throw new InvalidArgumentException(trans_html('Use a valid admission updates cursor.'));
            }
        }
        $rows = $this->updates->page($actor, $unreadOnly, $afterAt, $after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = [];
        foreach ($rows as $row) {
            if ($row['person_id'] !== $person->id->value) {
                throw new AccessDeniedException();
            }
            $items[] = $this->describe($row);
        }
        $count = $this->updates->unreadCount($actor);
        $this->samePerson($actor, $person);
        return ['items' => $items, 'unread_count' => $count, 'next' => $more
            ? ['at' => end($rows)['updated_at'], 'id' => end($rows)['id']] : null];
    }
    private function person(EntityId $actor): Person
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value
            || $person?->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException(trans_html('The current account needs its own person record.'));
        }
        return $person;
    }
    private function samePerson(EntityId $actor, Person $person): void
    {
        if ($this->person($actor)->id->value !== $person->id->value) {
            throw new AccessDeniedException();
        }
    }
    /** Metadata only: opening guidance remains a separately owner-authorized application read. */
    public function detail(EntityId $notice): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        $row = $this->owned($actor, $person, $notice) ?? throw new AccessDeniedException();
        if ($this->owned($actor, $person, $notice) !== $row) {
            throw new ConcurrentModificationException(trans_html('Reload the latest admission update.'));
        }
        $this->samePerson($actor, $person);
        return $this->describe($row);
    }
    private function describe(array $row): array
    {
        return ['id' => $row['id'], 'application_id' => $row['application_id'],
            'revision' => (int) $row['revision'], 'unread' => (int) $row['read_revision'] < (int) $row['revision'],
            'updated_at' => $row['updated_at'], 'program_code' => $row['program_code'],
            'program_title' => $row['program_title'], 'term_code' => $row['term_code']];
    }
    private function owned(EntityId $actor, Person $person, EntityId $notice): ?array
    {
        $row = $this->updates->owned($actor, $notice);
        if ($row === null) {
            return null;
        }
        if ($row['person_id'] !== $person->id->value) {
            throw new AccessDeniedException();
        }
        $row['revision'] = (int) $row['revision'];
        $row['read_revision'] = (int) $row['read_revision'];
        return $row;
    }
    /** @return array{unread:bool} */
    public function read(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, false);
    }
    public function delete(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, true);
    }
    private function mutate(EntityId $notice, int $revision, bool $delete): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if ($revision < 1 || $revision > 2000000000) {
            throw new InvalidArgumentException(trans_html('Reload admission updates before changing one.'));
        }
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision, $delete): array {
            $this->admissions->lockPerson($person->id);
            $this->samePerson($actor, $person);
            $owned = $this->owned($actor, $person, $notice);
            if ($owned === null && $delete) {
                $this->samePerson($actor, $person);
                return ['deleted' => true];
            }
            if ($owned === null) {
                throw new AccessDeniedException(trans_html('This account does not own the admission update.'));
            }
            $applicationId = new EntityId($owned['application_id']);
            $this->admissions->lockApplication($applicationId);
            $this->updates->lock($notice);
            $owned = $this->owned($actor, $person, $notice) ?? throw new AccessDeniedException();
            $application = $this->admissions->application($applicationId);
            if (
                $application->personId->value !== $person->id->value ||
                $owned['application_id'] !== $applicationId->value || $revision > $owned['revision'] ||
                ($delete && $revision !== $owned['revision'])
            ) {
                throw new ConcurrentModificationException(trans_html('Reload the latest admission update.'));
            }
            if ($delete) {
                if (!$this->updates->delete($actor, $notice, $revision)) {
                    throw new ConcurrentModificationException(trans_html('Reload the latest admission update.'));
                }
                $this->audit->record($actor, 'admissions.update_deleted', $notice);
                $this->verify($actor, $person, $notice, null, $application);
                return ['deleted' => true];
            }
            if ($revision > $owned['read_revision']) {
                $this->updates->markRead($actor, $notice, $revision);
                $this->audit->record($actor, 'admissions.update_read', $notice);
            }
            $owned['read_revision'] = max($revision, $owned['read_revision']);
            $this->verify($actor, $person, $notice, $owned, $application);
            return ['unread' => $owned['read_revision'] < $owned['revision']];
        });
    }
    private function verify(
        EntityId $actor,
        Person $person,
        EntityId $notice,
        ?array $expected,
        AdmissionApplication $application,
    ): void {
        if (
            $this->owned($actor, $person, $notice) !== $expected ||
            $this->admissions->application($application->id) != $application
        ) {
            throw new ConcurrentModificationException(trans_html('The admission update changed before completion.'));
        }
        $this->samePerson($actor, $person);
    }
}
