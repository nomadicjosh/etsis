<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use DateTimeImmutable;
use Domain\HumanResources\EmploymentTimeline;
use Domain\HumanResources\Event\EmploymentDatesWereRecorded;
use Domain\HumanResources\Repository\EmploymentDatesRepository;
use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\HumanResources\ValueObject\EmploymentTimelineId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal ledger: the calling service owns employee/authority locks and the required audit transaction. */
final readonly class EmploymentDatesLedger
{
    public function __construct(
        private EmploymentDatesRepository $rows,
        private HistoricalEventStore $history,
        private Clock $clock,
    ) {
    }
    /** @return array{root:EmploymentTimeline,receipt:bool} */
    public function inspect(
        EntityId $employee,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null
    ): array {
        $root = EmploymentTimeline::root(new EmploymentTimelineId($employee->value));
        $cursor = null;
        $head = null;
        $count = 0;
        $receipt = false;
        do {
            $page = $this->history->page(
                new StreamReference($root->aggregateId(), 'employment_lifecycle'),
                $cursor,
                100
            );
            if ($page->throughVersion > 2000) {
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
                if (++$count > 2000) {
                    throw new HistoryReadLimitException();
                }
                $root->accept($stored);
                $p = $stored->event->payload();
                if ($p['operation_id'] === $operation?->value) {
                    if ($stored->context->actor?->value !== $actor?->value || $p['intent'] !== $intent) {
                        throw new AccessDeniedException('Another employment-date operation.');
                    }
                    $receipt = true;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->rows->find($employee);
        if (
            $root->playhead() !== $head || ($row === null) !== ($head === 0)
            || ($row !== null && $row->facts() !== $root->dates()->facts())
        ) {
            throw new CorruptHistoryException();
        }
        return ['root' => $root, 'receipt' => $receipt];
    }
    public function requireCoverage(EntityId $employee, string $from, ?string $through, string $field): void
    {
        $dates = $this->inspect($employee)['root']->dates();
        if ($dates->version !== 0 && !$dates->covers($from, $through)) {
            \Domain\HumanResources\ValueObject\DefinitionRules::invalid(
                $field,
                'These dates must fit a recorded employment period. Ask HR to review the employment dates.'
            );
        }
    }
    public function record(
        EmploymentTimeline $root,
        EmploymentDates $next,
        EntityId $period,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        #[\SensitiveParameter] string $reason
    ): void {
        if ($root->playhead() >= 2000) {
            throw new HistoryReadLimitException();
        }
        if ($next->version !== $root->playhead() + 1) {
            throw new ConcurrentModificationException();
        }
        $event = EmploymentDatesWereRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => $root->operation(),
            'period_id' => $period->value, 'facts' => $next->facts(), 'intent' => $intent,
        ], ['__aggregate_type' => 'employment_lifecycle'])->withPlayhead($next->version);
        $prior = array_column($root->dates()->periods, null, 'id')[$period->value] ?? null;
        $p = array_column($next->periods, null, 'id')[$period->value];
        $effective = $this->clock->now();
        if ($prior === null || $prior['from'] !== $p['from']) {
            $effective = new DateTimeImmutable($p['from'], $effective->getTimezone());
        } elseif ($p['through'] !== null && $prior['through'] !== $p['through']) {
            $effective = new DateTimeImmutable($p['through'], $effective->getTimezone())->modify('+1 day');
        }
        $version = $root->playhead();
        $root->applyRecord($event);
        $this->history->append(
            new StreamReference($root->aggregateId(), 'employment_lifecycle'),
            $operation,
            $version,
            new EventContext($actor, $effective, $operation, reason: $reason),
            [$event]
        );
        $this->rows->save($next, $version);
    }
}
