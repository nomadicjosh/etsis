<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\PrimaryProgramWasObserved;
use Domain\Student\Event\PrimaryProgramWasSelected;
use Domain\Student\Repository\StudentPrimaryProgramRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;
use Domain\Student\StudentPrimaryProgram;
use Domain\Student\StudentProgram;
use Domain\Student\ValueObject\PrimaryProgramSchema;
use Domain\Student\ValueObject\StudentPrimaryProgramId;
use Qubus\Exception\Data\TypeException;

/** Internal ledger; authorized entry-point services own the student lock and atomic unit of work. */
final readonly class StudentPrimaryProgramLedger
{
    public function __construct(
        private StudentPrimaryProgramRepository $records,
        private StudentRecordRepository $students,
        private StudentProgramStatusLedger $statuses,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    /**
     * @throws TypeException
     */
    public function verify(Student $student): int
    {
        return $this->load($student)["root"]->playhead();
    }

    /**
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function current(Student $student): array
    {
        $root = $this->load($student)["root"];
        return $root->selection() ?? $this->selection($this->statuses->primaryAssignment($student));
    }

    /** @return array<string,mixed> */
    public function selection(StudentProgram $p): array
    {
        $program = $this->students->program($p->programId);
        $term = $this->students->term($p->entryTermId);
        $s = [
            "assignment_id" => $p->id->value,
            "program_id" => $p->programId->value,
            "entry_term_id" => $p->entryTermId->value,
            "program_code" => $program["code"],
            "program_title" => $program["title"],
            "entry_term_code" => $term["code"],
        ];
        PrimaryProgramSchema::selection($s);
        return $s;
    }

    /**
     * @return array<string,mixed>|null
     * @throws TypeException
     */
    public function receipt(Student $student, EntityId $operation, EntityId $actor): ?array
    {
        return $this->load($student, $operation, $actor)["receipt"];
    }

    /**
     * @param array<string,mixed> $selection
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function select(
        Student $student,
        StudentProgram $target,
        array $selection,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The primary selection actor changed.");
        }
        PrimaryProgramSchema::selection($selection);
        $this->statuses->verify($target);
        if (
            $target->studentId->value !== $student->id->value ||
            $target->status !== "active" ||
            $selection["assignment_id"] !== $target->id->value ||
            $selection["program_id"] !== $target->programId->value ||
            $selection["entry_term_id"] !== $target->entryTermId->value
        ) {
            throw new CorruptHistoryException();
        }
        $root = $this->load($student)["root"];
        $expected = $root->playhead();
        $at = $this->clock->now();
        if ($expected === 0) {
            $observed = PublishedGradeId::forVersion($operation, "primary.program.observation");
            $e = PrimaryProgramWasObserved::occur(
                new StudentPrimaryProgramId($student->id->value),
                [
                    "decision_id" => $observed->value,
                    "student_id" => $student->id->value,
                    "person_id" => $student->personId->value,
                    "institutional_entry_term_id" => $student->entryTermId->value,
                    "selection" => $this->selection($this->statuses->primaryAssignment($student)),
                ],
                ["__aggregate_type" => "student_primary_program"],
            )->withPlayhead(1);
            $root->acceptHistorical($e);
            $this->history->append(
                $this->stream($student->id),
                $observed,
                0,
                new EventContext(
                    $actor,
                    $at,
                    $operation,
                    reason: "Current primary pursuit observed before a reviewed selection; " .
                        "earlier primary history is unknown.",
                ),
                [$e],
            );
        }
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $e = PrimaryProgramWasSelected::occur(
            new StudentPrimaryProgramId($student->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $previous->value,
                "selection" => $selection,
            ],
            ["__aggregate_type" => "student_primary_program"],
        )->withPlayhead($root->playhead() + 1);
        $version = $root->playhead();
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($student->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$e],
        );
        $this->records->project($root, $student, $expected);
        return $this->result($root, $operation, $at->format(DATE_ATOM));
    }

    /**
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function timeline(Student $student, ?string $cursor): array
    {
        $root = $this->load($student)["root"];
        $page = $this->history->page($this->stream($student->id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $items[] = [
                "kind" => $stored->event instanceof PrimaryProgramWasObserved ? "observation" : "selection",
                "selection" => $stored->event->payload()["selection"],
                "effective_at" => $stored->context->effectiveAt?->format(DATE_ATOM),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" => true,
            "current_selection" =>
                $root->selection() ?? $this->selection($this->statuses->primaryAssignment($student)),
        ];
    }

    /**
     * @return array{root:StudentPrimaryProgram,receipt:?array<string,mixed>}
     * @throws TypeException
     */
    private function load(Student $student, ?EntityId $operation = null, ?EntityId $actor = null): array
    {
        $root = StudentPrimaryProgram::root(new StudentPrimaryProgramId($student->id->value));
        $cursor = null;
        $receipt = null;
        do {
            $page = $this->history->page($this->stream($student->id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if (
                    $stored->event instanceof PrimaryProgramWasSelected &&
                    $root->decision() === $operation?->value
                ) {
                    if ($stored->context->actor?->value !== $actor?->value) {
                        throw new AccessDeniedException("This primary selection belongs to another actor.");
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
        $m = $this->records->marker($student->id);
        $p = $this->statuses->primaryAssignment($student);
        if ($root->playhead() === 0) {
            if ($m !== null) {
                throw new CorruptHistoryException();
            }
        } else {
            $s = $root->selection() ?? throw new CorruptHistoryException();
            if (
                $root->person() !== $student->personId->value ||
                $root->entryTerm() !== $student->entryTermId->value ||
                $s["assignment_id"] !== $p->id->value ||
                $s["program_id"] !== $p->programId->value ||
                $s["entry_term_id"] !== $p->entryTermId->value ||
                $m !== [
                    "id" => $student->id->value,
                    "assignment_id" => $p->id->value,
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
    private function result(StudentPrimaryProgram $root, EntityId $operation, string $effective): array
    {
        return [
            "operation_id" => $operation->value,
            "selection" => $root->selection(),
            "version" => $root->playhead(),
            "effective_at" => $effective,
        ];
    }

    /**
     * @throws TypeException
     */
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new StudentPrimaryProgramId($id->value), "student_primary_program");
    }
}
