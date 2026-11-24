<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use Domain\Advising\AdvisorHistory;
use Domain\Advising\Repository\AdvisorRepository;
use Domain\Advising\ValueObject\AdvisorHistoryId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal retained assignment reader. Callers authorize; reading never assigns an advisor or grants access. */
final readonly class AdvisorHistoryReader
{
    public function __construct(private AdvisorRepository $records, private HistoricalEventStore $history)
    {
    }

    public function read(EntityId $student, EntityId $pursuit, HistoryReadBudget $budget): AdvisorHistory
    {
        $root = AdvisorHistory::root(new AdvisorHistoryId($pursuit->value));
        $stream = new StreamReference($root->aggregateId(), 'student_advisors');
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                if ($stored->event->payload()['student_id'] !== $student->value) {
                    throw new CorruptHistoryException();
                }
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->rows() !== $this->records->rows($pursuit)) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
}
