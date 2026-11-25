<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeImmutable;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\Repository\AdvisingCheckInRepository;
use Domain\Advising\Repository\AdvisingUpdatesRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class AdvisingUpdatesService
{
    public function __construct(
        private AdvisingUpdatesRepository $updates,
        private AdvisingCheckInRepository $checkins,
        private PersonAccountRepository $people,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function person(EntityId $actor): \Domain\Person\Person
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value
            || $person?->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $person;
    }

    private function source(array $row): void
    {
        $item = $this->checkins->find(
            new EntityId($row['student_id']),
            new EntityId($row['pursuit_id']),
            new EntityId($row['checkin_id'])
        ) ?? throw new CorruptHistoryException();
        $facts = $item->facts();
        $action = match ($row['status']) {
            'open' => 'record', 'closed' => 'close', 'cancelled' => 'cancel',
            default => throw new CorruptHistoryException(),
        };
        $receipt = $facts['receipts'][$action]['result'] ?? null;
        $at = $facts[$action === 'record' ? 'created_at' : 'resolved_at'];
        if (
            $facts['owner_user_id'] !== $row['owner_user_id'] || $receipt === null
            || $receipt['id'] !== $row['checkin_id'] || $receipt['version'] !== (int) $row['revision']
            || $receipt['status'] !== $row['status'] || $at === null
            || AdvisingCheckInNotifications::timestamp($at) !== $row['recorded_at']
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new \InvalidArgumentException(trans('Use the complete advising update cursor.'));
        }
        if ($afterAt !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $afterAt);
            if (!$date || $date->format('Y-m-d\TH:i:s.u\Z') !== $afterAt) {
                throw new \InvalidArgumentException(trans('Use a valid advising update cursor.'));
            }
        }
        $inventory = $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after);
        $more = count($inventory) > 20;
        $rows = array_slice($inventory, 0, 20);
        $items = [];
        foreach ($rows as $row) {
            $this->source($row);
            $items[] = array_intersect_key($row, array_flip([
                'id', 'student_id', 'pursuit_id', 'checkin_id', 'status', 'recorded_at',
                'program_code', 'program_title',
            ])) + ['revision' => (int) $row['revision'], 'unread' => $row['read_at'] === null];
        }
        if (
            $this->person($actor)->id->value !== $person->id->value
            || $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after) !== $inventory
        ) {
            throw new ConcurrentModificationException(trans('Advising updates changed. Refresh the list.'));
        }
        return ['items' => $items, 'next' => !$more ? null : [
            'at' => $rows[array_key_last($rows)]['recorded_at'], 'id' => $rows[array_key_last($rows)]['id'],
        ]];
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
        if ($revision < 0 || $revision > 2) {
            throw new \InvalidArgumentException(trans('Reload the current advising update.'));
        }
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision, $delete): array {
            $this->admissions->lockPerson($person->id);
            $this->person($actor);
            $row = $this->updates->owned($actor, $person->id, $notice);
            if ($row === null && $delete) {
                return ['deleted' => true];
            }
            if ($row === null) {
                throw new AccessDeniedException();
            }
            $this->updates->lock($notice);
            $row = $this->updates->owned($actor, $person->id, $notice) ?? throw new AccessDeniedException();
            if ((int) $row['revision'] !== $revision) {
                throw new ConcurrentModificationException(trans('Reload the current advising update.'));
            }
            if ($delete) {
                $this->updates->delete($actor, $person->id, $notice);
                $this->audit->record($actor, 'advising.update_deleted', $notice);
            } else {
                $this->source($row);
                if ($row['read_at'] === null) {
                    $row['read_at'] = AdvisingCheckInNotifications::timestamp($this->clock->now()->format(DATE_ATOM));
                    $this->updates->markRead($actor, $notice, $row['read_at']);
                    $this->audit->record($actor, 'advising.update_read', $notice);
                }
                $this->source($row);
            }
            if (
                $this->person($actor)->id->value !== $person->id->value
                || $this->updates->owned($actor, $person->id, $notice) != ($delete ? null : $row)
            ) {
                throw new ConcurrentModificationException(trans('Advising updates changed. Refresh the list.'));
            }
            return $delete ? ['deleted' => true] : ['unread' => false];
        });
    }
}
