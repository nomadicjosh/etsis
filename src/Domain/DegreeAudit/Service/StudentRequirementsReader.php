<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Repository\StudentRequirementsRepository;
use Domain\DegreeAudit\StudentRequirements;
use Domain\DegreeAudit\ValueObject\StudentRequirementsId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal bounded selection/projection comparison. Callers authorize; no selection or academic write. */
final readonly class StudentRequirementsReader
{
    public function __construct(
        private StudentRequirementsRepository $selections,
        private HistoricalEventStore $history,
    ) {
    }

    public function read(EntityId $pursuit, HistoryReadBudget $budget): StudentRequirements
    {
        $stream = new StreamReference(new StudentRequirementsId($pursuit->value), 'student_degree_requirements');
        $state = StudentRequirements::root($stream->aggregateId);
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $state->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $selection = $state->selection();
        $row = $this->selections->get($pursuit);
        if (($selection === null) !== ($row === null)) {
            throw new CorruptHistoryException();
        }
        if ($selection !== null && $row !== null) {
            unset($selection['previous_decision_id']);
            $expected = ['id' => $pursuit->value] + $selection + ['position' => $state->playhead()];
            if (count($row) !== count($expected)) {
                throw new CorruptHistoryException();
            }
            foreach ($expected as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        return $state;
    }
}
