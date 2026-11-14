<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentProgramWasObserved;
use Domain\Student\Event\StudentProgramStatusWasChanged;
use Domain\Student\Event\StudentProgramWasAssigned;
use Domain\Student\Repository\StudentProgramStatusRepository;
use Domain\Student\StudentProgram;
use Domain\Student\StudentProgramStatus;
use Domain\Student\ValueObject\StudentProgramStatusId;
use Domain\Student\ValueObject\StudentProgramStatusSchema;

/** Internal status ledger. Calling services authorize, lock the student and own the transaction. */
final readonly class StudentProgramStatusLedger
{
    public function __construct(
        private StudentProgramStatusRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    public function verify(StudentProgram $assignment, ?HistoryReadBudget $budget = null): int
    {
        return $this->load($assignment, budget: $budget)["root"]->playhead();
    }
    /** Resolve the exact active pursuit behind the student's primary program pointer. */
    public function primaryAssignment(\Domain\Student\Student $student): StudentProgram
    {
        $after = null;
        $found = null;
        do {
            $rows = $this->records->forProgram($student->id, $student->programId, $after);
            foreach ($rows as $p) {
                $this->verify($p);
                if ($p->status === "active") {
                    if ($found !== null) {
                        throw new CorruptHistoryException();
                    }
                    $found = $p;
                }
                $after = $p->id;
            }
        } while (count($rows) === 50);
        return $found ?? throw new CorruptHistoryException();
    }
    /** Current uniqueness checks must not trust a conflicting status on another pursuit. */
    public function assertNoActiveProgram(
        EntityId $student,
        EntityId $program,
        ?EntityId $excluding = null,
    ): void {
        $after = null;
        do {
            $rows = $this->records->forProgram($student, $program, $after);
            foreach ($rows as $p) {
                if ($p->id->value !== $excluding?->value) {
                    $this->verify($p);
                    if ($p->status === "active") {
                        throw new \Domain\Shared\Exception\ValidationException([
                            [
                                "field" => "program_id",
                                "message" => "This program already has an active assignment for the student.",
                            ],
                        ]);
                    }
                }
                $after = $p->id;
            }
        } while (count($rows) === 50);
    }
    /** Check both current active pursuits and an existing closed pursuit of the same program/entry term. */
    public function assertCanAssign(EntityId $student, EntityId $program, EntityId $term): void
    {
        $after = null;
        do {
            $rows = $this->records->forProgram($student, $program, $after);
            foreach ($rows as $p) {
                $this->verify($p);
                if ($p->status === "active" || $p->entryTermId->value === $term->value) {
                    throw new \Domain\Shared\Exception\ValidationException([
                        [
                            "field" => "program_id",
                            "message" =>
                                "This program already has an active assignment or this entry term already exists. " .
                                "Review the existing assignment.",
                        ],
                    ]);
                }
                $after = $p->id;
            }
        } while (count($rows) === 50);
    }
    /** Called only after a genuinely new assignment and its derived cohort are inserted in this unit of work.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function assigned(
        StudentProgram $assignment,
        array $source,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        ?EntityId $correlation = null,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The assignment actor changed.");
        }
        $root = $this->load($assignment)["root"];
        if ($root->playhead() !== 0) {
            throw new CorruptHistoryException();
        }
        $at = $this->clock->now();
        $e = StudentProgramWasAssigned::occur(
            new StudentProgramStatusId($assignment->id->value),
            [
                "decision_id" => $assignment->id->value,
                "assignment" => StudentProgramStatusSchema::facts($assignment),
                "source" => $source,
            ],
            ["__aggregate_type" => "student_program_status"],
        )->withPlayhead(1);
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($assignment->id),
            $assignment->id,
            0,
            new EventContext($actor, $at, $correlation ?? $assignment->id, reason: $reason),
            [$e],
        );
        $this->records->track($root);
        return $this->result($root, $assignment->id, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed>|null */
    public function receipt(StudentProgram $assignment, EntityId $operation, EntityId $actor): ?array
    {
        return $this->load($assignment, $operation, $actor)["receipt"];
    }
    /** @return array<string,mixed> */
    public function change(
        StudentProgram $assignment,
        string $status,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The program decision actor changed.");
        }
        $root = $this->load($assignment)["root"];
        $expected = $root->playhead();
        $at = $this->clock->now();
        if ($expected === 0) {
            $observed = PublishedGradeId::forVersion($operation, "program.status.observation");
            $e = StudentProgramWasObserved::occur(
                new StudentProgramStatusId($assignment->id->value),
                [
                    "decision_id" => $observed->value,
                    "assignment" => StudentProgramStatusSchema::facts($assignment),
                ],
                ["__aggregate_type" => "student_program_status"],
            )->withPlayhead(1);
            $root->acceptHistorical($e);
            $this->history->append(
                $this->stream($assignment->id),
                $observed,
                0,
                new EventContext(
                    $actor,
                    $at,
                    $operation,
                    reason: "Existing program assignment observed before its reviewed status decision; " .
                        "earlier history is unknown.",
                ),
                [$e],
            );
        }
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $event = StudentProgramStatusWasChanged::occur(
            new StudentProgramStatusId($assignment->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $previous->value,
                "status" => $status,
            ],
            ["__aggregate_type" => "student_program_status"],
        )->withPlayhead($root->playhead() + 1);
        $version = $root->playhead();
        $root->acceptHistorical($event);
        $this->history->append(
            $this->stream($assignment->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$event],
        );
        $this->records->project($root, $assignment->status, $expected);
        return $this->result($root, $operation, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed> */
    public function timeline(StudentProgram $assignment, ?string $cursor): array
    {
        $root = $this->load($assignment)["root"];
        $page = $this->history->page($this->stream($assignment->id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $observed = $stored->event instanceof StudentProgramWasObserved;
            $assigned = $stored->event instanceof StudentProgramWasAssigned;
            $items[] = [
                "kind" => $assigned ? "assignment" : ($observed ? "observation" : "decision"),
                "status" => $observed || $assigned ? $p["assignment"]["status"] : $p["status"],
                "effective_at" => $stored->context->effectiveAt?->format(DATE_ATOM),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "assignment_id" => $assignment->id->value,
            "status" => $assignment->status,
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" => $root->earlierHistoryUnknown(),
            "assignment_source" => $root->assignmentSource(),
            "items" => $items,
            "next_cursor" => $page->nextCursor,
        ];
    }
    /** @return array{root:StudentProgramStatus,receipt:?array<string,mixed>} */
    private function load(
        StudentProgram $assignment,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?HistoryReadBudget $budget = null,
    ): array {
        $root = StudentProgramStatus::root(new StudentProgramStatusId($assignment->id->value));
        $cursor = null;
        $receipt = null;
        do {
            $page = $budget === null
            ? $this->history->page($this->stream($assignment->id), $cursor, 20)
            : $budget->page($this->history, $this->stream($assignment->id), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if (
                    ($stored->event instanceof StudentProgramStatusWasChanged ||
                        $stored->event instanceof StudentProgramWasAssigned) &&
                    $root->decision() === $operation?->value
                ) {
                    if ($stored->context->actor?->value !== $actor?->value) {
                        throw new AccessDeniedException("This program decision belongs to another actor.");
                    }
                    $receipt = $this->result(
                        $root,
                        $operation ?? throw new CorruptHistoryException(),
                        $stored->context->effectiveAt?->format(DATE_ATOM) ??
                            throw new CorruptHistoryException(),
                    );
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $m = $this->records->marker($assignment->id);
        if ($root->playhead() === 0) {
            if ($m !== null) {
                throw new CorruptHistoryException();
            }
        } else {
            $facts = $root->facts() ?? throw new CorruptHistoryException();
            foreach (StudentProgramStatusSchema::facts($assignment) as $key => $value) {
                if ($facts[$key] !== $value) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                $m !== [
                    "id" => $assignment->id->value,
                    "student_id" => $assignment->studentId->value,
                    "stream_version" => $root->playhead(),
                    "last_decision_id" => $root->decision(),
                ]
            ) {
                throw new CorruptHistoryException();
            }
        }
        return ["root" => $root, "receipt" => $receipt];
    }
    /** @return array<string,mixed> */
    private function result(StudentProgramStatus $root, EntityId $operation, string $effective): array
    {
        return [
            "operation_id" => $operation->value,
            "assignment_id" => (string) $root->aggregateId(),
            "status" => ($root->facts() ?? throw new CorruptHistoryException())["status"],
            "version" => $root->playhead(),
            "effective_at" => $effective,
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new StudentProgramStatusId($id->value), "student_program_status");
    }
}
