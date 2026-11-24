<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\DegreeAudit\RecordedDegreeProgress;
use Domain\DegreeAudit\Repository\DegreeProgressRecordRepository;
use Domain\DegreeAudit\ValueObject\DegreeProgressRecordId;
use Domain\DegreeAudit\ValueObject\DegreeProgressSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal accepted-observation reader. Callers authorize; replay has no business effects. */
final readonly class RecordedProgressReader
{
    public function __construct(
        private DegreeProgressRecordRepository $records,
        private HistoricalEventStore $history,
    ) {
    }

    public function read(EntityId $id, HistoryReadBudget $budget): ?StoredEvent
    {
        $row = $this->records->find($id);
        $stream = new StreamReference(new DegreeProgressRecordId($id->value), 'recorded_degree_progress');
        $page = $budget->page($this->history, $stream);
        if ($row === null && $page->throughVersion === 0 && $page->events === []) {
            return null;
        }
        if (
            $row === null || $page->throughVersion !== 1 || count($page->events) !== 1 ||
            $page->nextCursor !== null
        ) {
            throw new CorruptHistoryException();
        }
        $stored = $page->events[0];
        $root = RecordedDegreeProgress::root(new DegreeProgressRecordId($id->value));
        $root->accept($stored);
        $expected = $this->projection($id, $root->snapshot(), $stored->context->effectiveAt);
        foreach ($expected as $key => $value) {
            if ($key === 'recorded_at') {
                if (new DateTimeImmutable($row[$key])->format('Y-m-d H:i:s') !== $value) {
                    throw new CorruptHistoryException();
                }
            } elseif ($row[$key] !== $value) {
                throw new CorruptHistoryException();
            }
        }
        $budget->checkTime();
        return $stored;
    }

    /** Deterministic support projection shared by original acceptance and read-only verification. */
    public function projection(EntityId $id, DegreeProgressSnapshot $snapshot, DateTimeImmutable $at): array
    {
        return [
            'id' => $id->value,
            'student_id' => $snapshot->data['student_id'],
            'pursuit_id' => $snapshot->data['pursuit_id'],
            'recorded_on' => $snapshot->data['as_of_date'],
            'recorded_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'source_hash' => $snapshot->fingerprint(),
        ];
    }
}
