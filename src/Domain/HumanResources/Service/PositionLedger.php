<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\EmployeePosition;
use Domain\HumanResources\Event\EmployeePositionWasRecorded;
use Domain\HumanResources\PositionHistory;
use Domain\HumanResources\Repository\PositionRepository;
use Domain\HumanResources\ValueObject\EmployeePositionId;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\HumanResources\TimeEntry;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal position history. Authorized services own parent locks, scope checks and transactions. */
final readonly class PositionLedger
{
    public const int MAX_EVENTS = 2000;
    public function __construct(
        private PositionRepository $rows,
        private HistoricalEventStore $history,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    /** Minimized choices for one already-authorized employee and work interval.
     * @return array{items:list<array{id:string,code:string,title:string,version:int}>,position_required:bool}
     */
    public function choices(EntityId $employee, TimeSlot $work): array
    {
        $cursor = null;
        $seen = [];
        $items = [];
        do {
            $page = $this->rows->page($employee, $cursor);
            foreach (array_slice($page, 0, 20) as $position) {
                if ($position->employeeId->value !== $employee->value) {
                    throw new AccessDeniedException('Another employee position.');
                }
                if (isset($seen[$position->id->value]) || count($seen) >= 500) {
                    throw new HistoryReadLimitException();
                }
                $seen[$position->id->value] = true;
                if ($this->covers($position, $work)) {
                    $items[] = ['id' => $position->id->value, 'code' => $position->job['code'],
                        'title' => $position->job['title'], 'version' => $position->version];
                    if (count($items) > 50) {
                        throw new HistoryReadLimitException();
                    }
                }
            }
            $cursor = count($page) > 20 ? $page[19]->id : null;
        } while ($cursor !== null);
        return ['items' => $items, 'position_required' => $seen !== []];
    }

    /** Resolve only the selected position's stream, not every projected choice. */
    public function select(
        EntityId $employee,
        TimeSlot $work,
        ?EntityId $id,
        ?int $version
    ): ?EmployeePosition {
        if (($id === null) !== ($version === null)) {
            DefinitionRules::invalid('position_id', 'Review the work position and its current version.');
        }
        if ($id === null) {
            $choices = $this->choices($employee, $work);
            if (!$choices['position_required']) {
                return null;
            }
            if (count($choices['items']) !== 1) {
                DefinitionRules::invalid('position_id', $choices['items'] === []
                    ? 'No recorded position covers this work. Ask HR to review the assignment dates.'
                    : 'Choose the position worked before submitting this entry.');
            }
            $id = new EntityId($choices['items'][0]['id']);
            $version = $choices['items'][0]['version'];
        }
        $position = $this->verified($employee, $id);
        if ($position->version !== $version) {
            throw new ConcurrentModificationException('Review the work position again.');
        }
        if (!$this->covers($position, $work)) {
            DefinitionRules::invalid('position_id', 'The selected position must cover the full work interval.');
        }
        return $position;
    }

    public function requireApprovalCoverage(TimeEntry $entry): void
    {
        if ($entry->positionId === null) {
            return;
        }
        $position = $this->verified($entry->employeeId, $entry->positionId);
        if ($entry->positionVersion === null || $entry->positionVersion > $position->version) {
            throw new CorruptHistoryException();
        }
        if (!$this->covers($position, $entry->work)) {
            DefinitionRules::invalid(
                'position_id',
                'This assignment no longer covers the work. Review it before approval.'
            );
        }
    }

    /** Selected historical work stays readable after an assignment ends or is marked void. */
    public function verifyEntryReference(TimeEntry $entry): void
    {
        if ($entry->positionId === null) {
            return;
        }
        $position = $this->verified($entry->employeeId, $entry->positionId);
        if ($entry->positionVersion === null || $entry->positionVersion > $position->version) {
            throw new CorruptHistoryException();
        }
    }

    /** Batch protected labels; retained code/title/terms cannot change within a position identity.
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    public function labelEntries(EntityId $employee, array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (($entry['position_id'] ?? null) !== null) {
                $ids[$entry['position_id']] = new EntityId($entry['position_id']);
            }
        }
        $positions = [];
        foreach ($this->rows->forEntries($employee, array_values($ids)) as $position) {
            if ($position->employeeId->value !== $employee->value) {
                throw new CorruptHistoryException();
            }
            $positions[$position->id->value] = $position;
        }
        foreach ($entries as &$entry) {
            $entry['position'] = null;
            if (($entry['position_id'] ?? null) !== null) {
                $p = $positions[$entry['position_id']] ?? throw new CorruptHistoryException();
                if (
                    !is_int($entry['position_version']) || $entry['position_version'] < 1
                    || $entry['position_version'] > $p->version
                ) {
                    throw new CorruptHistoryException();
                }
                $entry['position'] = ['id' => $p->id->value, 'code' => $p->job['code'], 'title' => $p->job['title'],
                    'accepted_version' => $entry['position_version'], 'current_version' => $p->version,
                    'voided' => $p->voided];
            }
            unset($entry['position_id'], $entry['position_version']);
        }
        unset($entry);
        return $entries;
    }

    /** Internal selected-appointment verification; the caller must authorize the employee. */
    public function verified(
        EntityId $employee,
        EntityId $id,
        ?HistoryReadBudget $budget = null,
    ): EmployeePosition {
        $position = $this->rows->find($id) ?? throw new CorruptHistoryException();
        if ($position->employeeId->value !== $employee->value) {
            throw new AccessDeniedException('The work position belongs to another employee.');
        }
        $this->verify($this->load($id, budget: $budget)['root'], $position);
        return $position;
    }

    private function covers(EmployeePosition $position, TimeSlot $work): bool
    {
        $zone = $this->clock->now()->getTimezone();
        return !$position->voided && $work->startsAt->setTimezone($zone)->format('Y-m-d') >= $position->from
        && ($position->through === null
                || $work->endsAt->modify('-1 microsecond')->setTimezone($zone)->format('Y-m-d') <= $position->through);
    }
    public function record(
        PositionHistory $root,
        EmployeePosition $next,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        string $action,
        #[\SensitiveParameter] string $reason
    ): void {
        if ($root->playhead() >= self::MAX_EVENTS) {
            throw new HistoryReadLimitException();
        }
        $payload = ['operation_id' => $operation->value, 'previous_operation_id' => $root->operation(),
            'action' => $action, 'facts' => $next->facts(), 'intent' => $intent];
        $event = EmployeePositionWasRecorded::occur(
            $root->aggregateId(),
            $payload,
            ['__aggregate_type' => 'employment_position']
        )->withPlayhead($root->playhead() + 1);
        $version = $root->playhead();
        $now = $this->clock->now();
        $effective = $action === 'assigned' ? new DateTimeImmutable($next->from, $now->getTimezone()) : $now;
        if ($action === 'end_changed' && $next->through !== null) {
            $effective = new DateTimeImmutable($next->through, $now->getTimezone())->modify('+1 day');
        }
        $root->applyRecord($event);
        $this->history->append(
            $this->stream($next->id),
            $operation,
            $version,
            new EventContext($actor, $effective, $operation, reason: $reason),
            [$event]
        );
        $this->rows->save($next, $version);
        $this->audit->record($actor, 'employee.position.' . $action, $next->id);
    }
    /** @return array{root:PositionHistory,receipt:bool,last:?StoredEvent} */
    public function load(
        EntityId $id,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null,
        ?HistoryReadBudget $budget = null,
    ): array {
        $root = PositionHistory::root(new EmployeePositionId($id->value));
        $cursor = null;
        $head = null;
        $count = 0;
        $receipt = false;
        $last = null;
        do {
            $page = $budget === null
            ? $this->history->page($this->stream($id), $cursor, 100)
            : $budget->page($this->history, $this->stream($id), $cursor);
            if ($page->throughVersion > self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            $head ??= $page->throughVersion;
            if (
                $head !== $page->throughVersion || ($page->nextCursor !== null
                && ($page->events === [] || $page->nextCursor === $cursor))
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                if (++$count > self::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $root->accept($stored);
                $last = $stored;
                if ($stored->event->payload()['operation_id'] === $operation?->value) {
                    if (
                        $stored->context->actor?->value !== $actor?->value
                        || $stored->event->payload()['intent'] !== $intent
                    ) {
                        throw new AccessDeniedException('The position operation belongs to another actor or intent.');
                    }
                    $receipt = true;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $head) {
            throw new CorruptHistoryException();
        }
        return ['root' => $root, 'receipt' => $receipt, 'last' => $last];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new EmployeePositionId($id->value), 'employment_position');
    }
    public function verify(PositionHistory $root, ?EmployeePosition $row): void
    {
        if ($root->position() === null || $root->position()->facts() !== $row?->facts()) {
            throw new CorruptHistoryException();
        }
    }
}
