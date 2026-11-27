<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Registration\Repository\EnrollmentUpdatesRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

final readonly class EnrollmentUpdatesService
{
    public function __construct(
        private EnrollmentUpdatesRepository $updates,
        private PersonAccountRepository $accounts,
        private AdmissionsRepository $people,
        private RegistrationRepository $records,
        private EnrollmentLedger $ledger,
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
            $person?->ownerUserId?->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $person;
    }

    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        $at = $afterAt === null ? null : DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $afterAt,
            new DateTimeZone('UTC'),
        );
        if (
            ($afterAt === null) !== ($after === null) ||
            ($afterAt !== null && (!$at || $at->format('Y-m-d\TH:i:s.u\Z') !== $afterAt))
        ) {
            throw new InvalidArgumentException(trans('Use the complete course-registration notification cursor.'));
        }
        $inventory = $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after);
        if (
            $this->person($actor)->id->value !== $person->id->value ||
            $inventory !== $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after)
        ) {
            throw new ConcurrentModificationException(trans(
                'Course-registration notifications changed. Refresh the list.',
            ));
        }
        $rows = array_slice($inventory, 0, 20);
        $items = array_map(static fn(array $row): array => array_intersect_key($row, array_flip([
            'id', 'status', 'recorded_at', 'course_code', 'course_title', 'section_code', 'term_code',
        ])) + ['revision' => (int) $row['revision'], 'unread' => $row['read_at'] === null], $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $items,
            'next' => count($inventory) > 20 ? ['at' => $last['recorded_at'], 'id' => $last['id']] : null];
    }

    public function read(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, false);
    }

    public function delete(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, true);
    }

    private function source(array $row, HistoryReadBudget $budget): array
    {
        $source = $this->ledger->notificationFacts(
            new EntityId($row['enrollment_id']),
            new EntityId($row['student_id']),
            $budget,
        );
        if (
            $source['decision_id'] !== $row['decision_id'] || $source['revision'] !== (int) $row['revision'] ||
            $source['status'] !== $row['status'] || $source['facts']['section_id'] !== $row['section_id']
        ) {
            throw new CorruptHistoryException();
        }
        return $source;
    }

    private function mutate(EntityId $notice, int $revision, bool $delete): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if ($revision < 1) {
            throw new InvalidArgumentException(trans('Reload the current course-registration notification.'));
        }
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision, $delete): array {
            $initial = $this->updates->owned($actor, $person->id, $notice);
            if ($initial === null) {
                if ($delete) {
                    return ['deleted' => true];
                }
                throw new AccessDeniedException();
            }
            // Match the registration command's student/person/section lock order.
            $this->records->lockStudent(new EntityId($initial['student_id']));
            $this->people->lockPerson($person->id);
            $this->records->lockSection(new EntityId($initial['section_id']));
            $this->updates->lock($notice);
            $row = $this->updates->owned($actor, $person->id, $notice);
            if (
                $this->person($actor)->id->value !== $person->id->value || $row !== $initial ||
                (int) $row['revision'] !== $revision
            ) {
                throw new ConcurrentModificationException(trans(
                    'Reload the current course-registration notification.',
                ));
            }
            $budget = new HistoryReadBudget();
            $source = $delete ? null : $this->source($row, $budget);
            if ($delete) {
                $this->updates->delete($actor, $person->id, $notice);
                $this->audit->record($actor, 'enrollment.notification_deleted', $notice);
            } elseif ($row['read_at'] === null) {
                $row['read_at'] = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                $this->updates->markRead($actor, $notice, $row['read_at']);
                $this->audit->record($actor, 'enrollment.notification_read', $notice);
            }
            if (
                $this->person($actor)->id->value !== $person->id->value ||
                $this->updates->owned($actor, $person->id, $notice) !== ($delete ? null : $row) ||
                (!$delete && $this->source($row, $budget) !== $source)
            ) {
                throw new ConcurrentModificationException(trans(
                    'Course-registration notifications changed. Refresh the list.',
                ));
            }
            return $delete ? ['deleted' => true] : ['unread' => false];
        });
    }
}
