<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentStandingWasCorrected;
use Domain\Student\Repository\StudentStandingRepository;
use Domain\Student\StudentStanding;
use Domain\Student\ValueObject\StandingPeriods;
use Domain\Student\ValueObject\StudentStandingId;
use Domain\Transcript\Service\TranscriptStandingSource;

/** Internal shared ledger. Callers authorize reads and own command transactions; replay has no effects. */
final readonly class StudentStandingLedger implements TranscriptStandingSource
{
    public function __construct(
        private StudentStandingRepository $records,
        private HistoricalEventStore $history,
        private StandingProposalPolicyService $policies,
    ) {
    }

    public function snapshot(EntityId $student, EntityId $pursuit, string $through, ?array $definitions = null): array
    {
        StandingPeriods::date($through);
        $head = $this->latest($student, $pursuit);
        $rows = $head === null ? [] : new StandingPeriods($head->event->payload()["rows"])->rows;
        $rows = array_values(
            array_filter($rows, static fn(array $row): bool => $row["starts_on"] <= $through
                && ($definitions === null || in_array($row['definition_id'], $definitions, true))),
        );
        usort($rows, static fn(array $a, array $b): int => strcmp($a["starts_on"], $b["starts_on"]));
        return [
            "pursuit_id" => $pursuit->value,
            "through" => $through,
            "stream_version" => $head?->event->playhead() ?? 0,
            "periods" => array_map(
                static fn(array $row): array => array_intersect_key(
                    $row,
                    array_flip(["code", "title", "starts_on", "ends_on"]),
                ),
                $rows,
            ),
        ] + ($definitions === null ? [] : ['filtered' => true]);
    }

    public function latest(EntityId $student, EntityId $pursuit): ?\Domain\Shared\EventSourcing\StoredEvent
    {
        $latest = $this->history->latest($this->stream($pursuit));
        $expected = [];
        if ($latest !== null) {
            StudentStanding::validate(
                $data = $latest->event->payload(),
                $latest->event instanceof StudentStandingWasCorrected,
            );
            if (
                $data["student_id"] !== $student->value ||
                $data["pursuit_id"] !== $pursuit->value ||
                $latest->context->origin !== "command" ||
                $latest->context->actor === null ||
                $latest->context->reason === null ||
                $latest->context->effectiveAt === null ||
                $latest->context->correlation?->value !== $data["operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $proof = StudentStanding::proposalEvidence($latest->event);
            if ($proof !== null) {
                $this->policies->verifyEvidence($proof, new HistoryReadBudget());
            }
            $expected = new StandingPeriods($data["rows"])->rows;
        }
        if ($expected !== $this->records->rows($student, $pursuit)) {
            throw new CorruptHistoryException();
        }
        return $latest;
    }

    public function load(
        EntityId $student,
        EntityId $pursuit,
        ?HistoryReadBudget $budget = null,
    ): StudentStanding {
        $root = StudentStanding::root(new StudentStandingId($pursuit->value));
        $budget ??= new HistoryReadBudget();
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($pursuit), $cursor);
            foreach ($page->events as $stored) {
                if ($stored->event->payload()["student_id"] !== $student->value) {
                    throw new CorruptHistoryException();
                }
                $root->accept($stored);
                $proof = StudentStanding::proposalEvidence($stored->event);
                if ($proof !== null) {
                    $this->policies->verifyEvidence($proof, $budget);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->rows() !== $this->records->rows($student, $pursuit)) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    private function stream(EntityId $pursuit): StreamReference
    {
        return new StreamReference(new StudentStandingId($pursuit->value), "student_standing");
    }
}
