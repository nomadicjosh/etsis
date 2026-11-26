<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StandingUpdatesRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentProgram;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

/** Account-pinned disposable notices; only the existing owner presentation exposes standing facts. */
final readonly class StandingUpdatesService
{
    public function __construct(
        private StandingUpdatesRepository $updates,
        private StudentRecordRepository $students,
        private AdmissionsRepository $people,
        private PersonAccountRepository $accounts,
        private StudentStandingService $standing,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function person(EntityId $actor): Person
    {
        $person = $this->accounts->findByUser($actor);
        if (
            $person?->ownerUserId?->value !== $actor->value
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $person;
    }

    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException(trans_html('Use the complete standing notification cursor.'));
        }
        if ($afterAt !== null) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $afterAt);
            if (!$at || $at->format('Y-m-d\TH:i:s.u\Z') !== $afterAt) {
                throw new InvalidArgumentException(trans_html('Use a valid standing notification cursor.'));
            }
        }
        $inventory = $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after);
        $rows = array_slice($inventory, 0, 20);
        $items = array_map(static fn (array $row): array => array_intersect_key(
            $row,
            array_flip(['id', 'status', 'recorded_at', 'program_code', 'program_title'])
        )
            + ['revision' => (int) $row['revision'], 'unread' => $row['read_at'] === null], $rows);
        if (
            $this->person($actor)->id->value !== $person->id->value
            || $inventory !== $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after)
        ) {
            throw new ConcurrentModificationException(trans_html('Standing notifications changed. Reopen the list.'));
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $items,
            'next' => count($inventory) > 20 ? ['at' => $last['recorded_at'], 'id' => $last['id']] : null];
    }

    /** Commands and notice mutations share student → person → notice ordering. */
    private function lock(EntityId $actor, Person $person, EntityId $notice, array $row): StudentProgram
    {
        $this->students->lock(new EntityId($row['student_id']));
        $this->people->lockPerson($person->id);
        $this->updates->lock($notice);
        $pursuit = $this->students->assignment(new EntityId($row['pursuit_id']));
        if (
            $this->person($actor)->id->value !== $person->id->value
            || $this->updates->owned($actor, $person->id, $notice) !== $row
        ) {
            throw new ConcurrentModificationException(trans_html('Standing notifications changed. Reopen the list.'));
        }
        return $pursuit;
    }

    private function verify(
        EntityId $actor,
        Person $person,
        EntityId $notice,
        ?array $row,
        StudentProgram $pursuit,
    ): void {
        if (
            $this->person($actor)->id->value !== $person->id->value
            || $this->people->student($pursuit->studentId)->personId->value !== $person->id->value
            || $this->students->assignment($pursuit->id) != $pursuit
            || $this->updates->owned($actor, $person->id, $notice) != $row
        ) {
            throw new ConcurrentModificationException(
                trans_html('The standing notification changed before completion.')
            );
        }
    }

    public function detail(EntityId $notice): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        return $this->transactions->run(function () use ($actor, $person, $notice): array {
            $row = $this->updates->owned($actor, $person->id, $notice) ?? throw new AccessDeniedException();
            $pursuit = $this->lock($actor, $person, $notice, $row);
            $standing = $this->standing->read(
                $pursuit->studentId,
                $pursuit->id,
                expectedVersion: (int) $row['revision'],
                expectedOperation: new EntityId($row['operation_id']),
            );
            $program = $this->students->program($pursuit->programId);
            $this->verify($actor, $person, $notice, $row, $pursuit);
            return ['program_code' => $program['code'], 'program_title' => $program['title'],
                'recorded_at' => $row['recorded_at']] + $standing;
        });
    }

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
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision, $delete): array {
            $row = $this->updates->owned($actor, $person->id, $notice);
            if ($row === null && $delete) {
                return ['deleted' => true];
            }
            if ($row === null) {
                throw new AccessDeniedException();
            }
            $pursuit = $this->lock($actor, $person, $notice, $row);
            if ((int) $row['revision'] !== $revision) {
                throw new ConcurrentModificationException(trans_html('Reload the current standing notification.'));
            }
            if ($delete) {
                $this->updates->delete($actor, $person->id, $notice);
                $this->audit->record($actor, 'standing.notification_deleted', $notice);
            } elseif ($row['read_at'] === null) {
                $row['read_at'] = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                $this->updates->markRead($actor, $notice, $row['read_at']);
                $this->audit->record($actor, 'standing.notification_read', $notice);
            }
            $this->verify($actor, $person, $notice, $delete ? null : $row, $pursuit);
            return $delete ? ['deleted' => true] : ['unread' => false];
        });
    }
}
