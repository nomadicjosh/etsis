<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use Domain\AcademicRecord\Event\AcademicWorkPursuitWasRecorded;
use Domain\AcademicRecord\Repository\WorkPursuitRepository;
use Domain\AcademicRecord\ValueObject\WorkPursuitId;
use Domain\AcademicRecord\WorkPursuit;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal ledger; creators and reviewed office commands own authorization and the student lock. */
final readonly class WorkPursuitLedger
{
    public function __construct(
        private WorkPursuitRepository $records,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    public function load(EntityId $credit, ?HistoryReadBudget $budget = null): WorkPursuit
    {
        $budget ??= new HistoryReadBudget();
        $root = WorkPursuit::root(new WorkPursuitId($credit->value));
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($credit), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (self::fingerprint($root->projection()) !== self::fingerprint($this->records->projection($credit))) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    /** Internal creation only, after the genuine institutional/transfer award is projected.
     * @param array<string,mixed> $credit Canonical credit facts supplied by the actual creator.
     */
    public function attributeNew(array $credit, EntityId $assignment, EntityId $actor): void
    {
        $id = new EntityId($credit['id']);
        $operation = PublishedGradeId::forVersion($id, 'academic.work.pursuit');
        $this->record($id, [
            'decision_id' => $operation->value, 'previous_decision_id' => null, 'action' => 'attribute',
            'source' => self::source($credit), 'assignment_id' => $assignment->value,
            'academic_version' => 1, 'work_hash' => self::fingerprint($credit),
        ], 0, new EventContext(
            $actor,
            $this->clock->now(),
            $operation,
            reason: 'Pursuit attribution recorded with the accepted academic-work creation.'
        ));
    }

    /** @param array<string,mixed> $payload
     * @return array{
     *     receipt:array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>},
     *     new:bool
     * }
     */
    public function record(
        EntityId $credit,
        array $payload,
        int $expectedVersion,
        EventContext $context,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->transactions->run(function () use (
            $credit,
            $payload,
            $expectedVersion,
            $context,
            $budget
        ): array {
            if ($context->actor?->value !== $this->access->authenticatedUserId()->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The academic-work actor changed.');
            }
            $root = $this->load($credit, $budget);
            $event = AcademicWorkPursuitWasRecorded::occur(
                new WorkPursuitId($credit->value),
                $payload,
                ['__aggregate_type' => 'work_pursuit']
            )->withPlayhead($expectedVersion + 1);
            $receipt = $this->history->append(
                $this->stream($credit),
                new EntityId($payload['decision_id']),
                $expectedVersion,
                $context,
                [$event]
            );
            $new = $receipt['event_ids'] === [(string) $event->eventId()];
            if ($new) {
                $root->acceptHistorical($event);
                $this->records->save($root, $expectedVersion);
            }
            return ['receipt' => $receipt, 'new' => $new];
        });
    }

    /** @param array<string,mixed> $credit
     * @return array{student_id:string,credit_id:string,origin:string,source_id:string}
     */
    public static function source(array $credit): array
    {
        return ['student_id' => $credit['student_id'], 'credit_id' => $credit['id'],
            'origin' => $credit['credit_type'], 'source_id' => $credit['source_id']];
    }

    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(self::ordered($value), JSON_THROW_ON_ERROR));
    }
    private static function ordered(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            $value = array_map(self::ordered(...), $value);
        }
        return $value;
    }
    public function stream(EntityId $credit): StreamReference
    {
        return new StreamReference(new WorkPursuitId($credit->value), 'work_pursuit');
    }
}
