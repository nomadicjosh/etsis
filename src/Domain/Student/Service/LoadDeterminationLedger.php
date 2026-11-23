<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\EnrollmentLoadDetermination;
use Domain\Student\Repository\LoadDeterminationRepository;
use Domain\Student\ValueObject\LoadDeterminationId;
use Domain\Student\ValueObject\LoadDeterminationSnapshot;

/** Shared retained-decision validation and deterministic projection; callers authorize and own transactions. */
final readonly class LoadDeterminationLedger
{
    public function __construct(
        private LoadDeterminationRepository $records,
        private HistoricalEventStore $history,
    ) {
    }
    public function restore(EntityId $id, ?HistoryReadBudget $budget = null): ?StoredEvent
    {
        $budget ??= new HistoryReadBudget();
        $row = $this->records->find($id);
        $page = $budget->page($this->history, $this->stream($id));
        if ($page->throughVersion === 0 && $page->events === [] && $row === null) {
            return null;
        }
        if (
            $page->throughVersion !== 1 ||
            count($page->events) !== 1 ||
            $page->nextCursor !== null ||
            $row === null
        ) {
            throw new CorruptHistoryException();
        }
        $stored = $page->events[0];
        $root = EnrollmentLoadDetermination::root(new LoadDeterminationId($id->value));
        $root->accept($stored);
        $expected = $this->projection($id, $root->snapshot(), $stored->context->effectiveAt);
        foreach ($expected as $key => $value) {
            if ($key === "recorded_at") {
                if (new \DateTimeImmutable($row[$key])->format("Y-m-d H:i:s") !== $value) {
                    throw new CorruptHistoryException();
                }
            } elseif (
                is_int($value) ? (string) $row[$key] !== (string) $value : $row[$key] !== $value
            ) {
                throw new CorruptHistoryException();
            }
        }
        return $stored;
    }
    public function projection(
        EntityId $id,
        LoadDeterminationSnapshot $snapshot,
        \DateTimeImmutable $at,
    ): array {
        $d = $snapshot->data;
        return [
            "id" => $id->value,
            "student_id" => $d["student_id"],
            "pursuit_id" => $d["pursuit_id"],
            "term_id" => $d["term"]["id"],
            "term_code" => $d["term"]["code"],
            "program_code" => $d["program"]["code"],
            "program_title" => $d["program"]["title"],
            "determined_on" => $d["as_of_date"],
            "recorded_at" => $at->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
            "registered_credits_milli" => $snapshot->creditsMilli,
            "enrollment_count" => count($d["sources"]),
            "classification_code" => $snapshot->classification["code"] ?? null,
            "classification_title" => $snapshot->classification["label"] ?? null,
            "policy_version_id" => $d["policy"]["version_id"],
            "policy_source" => $d["policy"]["source"],
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(
            new LoadDeterminationId($id->value),
            "enrollment_load_determination",
        );
    }
}
