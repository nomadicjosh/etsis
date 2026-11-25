<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\GraduateCourseApprovals;
use Domain\Registration\Repository\GraduateApprovalState;
use Domain\Registration\ValueObject\GraduateCourseApprovalsId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal bounded reader. Calling services own scope authorization, student locks and transaction boundaries. */
final readonly class GraduateApprovalLedger
{
    public function __construct(
        private HistoricalEventStore $history,
        private GraduateApprovalState $state,
    ) {
    }

    public function read(EntityId $pursuit, HistoryReadBudget $budget): GraduateCourseApprovals
    {
        $version = $this->state->current($pursuit);
        $stream = self::stream($pursuit);
        $root = GraduateCourseApprovals::root($stream->aggregateId);
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion || $root->playhead() !== $version) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    public static function stream(EntityId $pursuit): StreamReference
    {
        return new StreamReference(
            new GraduateCourseApprovalsId($pursuit->value),
            "graduate_course_approvals",
        );
    }
}
