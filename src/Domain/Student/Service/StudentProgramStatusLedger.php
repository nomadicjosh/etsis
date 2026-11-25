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
use Domain\Student\Event\StudentProgramWasReplaced;
use Domain\Student\Repository\StudentProgramStatusRepository;
use Domain\Student\StudentProgram;
use Domain\Student\StudentProgramStatus;
use Domain\Student\ValueObject\StudentProgramStatusId;
use Domain\Student\ValueObject\StudentProgramStatusSchema;
use Qubus\Exception\Data\TypeException;

/** Internal pursuit ledger for status and cohort decisions. Callers authorize, lock and own the transaction. */
final readonly class StudentProgramStatusLedger
{
    public function __construct(
        private StudentProgramStatusRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
        private \Domain\Catalog\Repository\CatalogYearRepository $catalogs,
    ) {
    }

    /**
     * @throws TypeException
     */
    public function verify(StudentProgram $assignment, ?HistoryReadBudget $budget = null): int
    {
        return $this->load($assignment, budget: $budget)["root"]->playhead();
    }
    /** Resolve the exact active pursuit behind the student's primary program pointer. */
    public function primaryAssignment(
        \Domain\Student\Student $student,
        ?HistoryReadBudget $budget = null,
        bool $allowClosed = false,
    ): StudentProgram {
        return $this->primaryContext($student, $budget, $allowClosed)["assignment"];
    }

    /** One bounded pass resolves identity, source facts and ambiguity together.
     * @return array{assignment:StudentProgram,context:array<string,mixed>}
     */
    public function primaryContext(
        \Domain\Student\Student $student,
        ?HistoryReadBudget $budget = null,
        bool $allowClosed = false,
    ): array {
        $budget ??= new HistoryReadBudget();
        $after = null;
        $found = $closed = null;
        $closedCount = 0;
        do {
            $rows = $this->records->forProgram($student->id, $student->programId, $after);
            foreach ($rows as $p) {
                $candidate = ["assignment" => $p, "context" => $this->documentContext($p, $budget)];
                if ($p->status === "active") {
                    if ($found !== null) {
                        throw new CorruptHistoryException();
                    }
                    $found = $candidate;
                } else {
                    $closed = $candidate;
                    ++$closedCount;
                }
                $after = $p->id;
            }
        } while (count($rows) === 50);
        return $found ?? ($allowClosed && $closedCount === 1 ? $closed : throw new CorruptHistoryException());
    }
    /** Source facts remain available after a pursuit closes; no active-student requirement.
     * @return array<string,mixed>
     */
    public function documentContext(StudentProgram $assignment, HistoryReadBudget $budget): array
    {
        $root = $this->load($assignment, budget: $budget)["root"];
        return [
            "assignment_id" => $assignment->id->value,
            "program_id" => $assignment->programId->value,
            "entry_term_id" => $assignment->entryTermId->value,
            "status" => $assignment->status,
            "stream_version" => $root->playhead(),
            "earlier_history_unknown" => $root->earlierHistoryUnknown(),
            "source" => $root->assignmentSource(),
            "cohort" => $root->cohort(),
            "cohort_version" => $root->cohortVersion(),
        ];
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
    /**
     * Check both current active pursuits and an existing closed pursuit of the same program/entry term.
     * @throws TypeException
     */
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
    /**
     * Called only after a genuinely new assignment and its derived cohort are inserted in this unit of work.
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     * @throws TypeException
     */
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
    /**
     * @return array<string,mixed>|null
     * @throws TypeException
     */
    public function receipt(StudentProgram $assignment, EntityId $operation, EntityId $actor): ?array
    {
        return $this->load($assignment, $operation, $actor)["receipt"];
    }
    /** @return array<string,mixed> */
    public function cohortContext(StudentProgram $assignment, ?HistoryReadBudget $budget = null): array
    {
        $loaded = $this->load($assignment, budget: $budget);
        $root = $loaded["root"];
        return [
            "binding" => $loaded["binding"],
            "catalog" => $loaded["catalog"],
            "stream_version" => $root->playhead(),
            "history_recorded" => $root->cohortVersion() !== null,
            'source_recorded' => $root->cohortVersion() !== null
                || ($root->assignmentSource()['cohort'] ?? null) !== null,
            "earlier_history_unknown" => $root->cohortEarlierUnknown($loaded["binding"]["version"] ?? -1),
        ];
    }
    /** @param array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    public function changeCohort(
        StudentProgram $assignment,
        array $catalog,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        int $expectedVersion,
        int $expectedBindingVersion,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The cohort decision actor changed.");
        }
        $loaded = $this->load($assignment);
        $root = $loaded["root"];
        $expected = $root->playhead();
        if (
            $expected !== $expectedVersion ||
            ($loaded["binding"]["version"] ?? -1) !== $expectedBindingVersion
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                "The reviewed pursuit or cohort changed.",
            );
        }
        $at = $this->clock->now();
        if ($expected === 0) {
            $observation = StudentProgramWasObserved::occur(
                new StudentProgramStatusId($assignment->id->value),
                [
                    "decision_id" => PublishedGradeId::forVersion($operation, "cohort.observation")->value,
                    "assignment" => StudentProgramStatusSchema::facts($assignment),
                ],
                ["__aggregate_type" => "student_program_status"],
            )->withPlayhead(1);
            $root->acceptHistorical($observation);
            $this->history->append(
                $this->stream($assignment->id),
                PublishedGradeId::forVersion($operation, "cohort.observation"),
                0,
                new EventContext($actor, $at, $operation, reason: $reason),
                [$observation],
            );
        }
        $version = $loaded["binding"]["version"] ?? -1;
        $event = \Domain\Student\Event\StudentCohortWasChanged::occur(
            new StudentProgramStatusId($assignment->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "previous_catalog" => $loaded["catalog"],
                "catalog" => $catalog,
                "previous_binding_version" => $version,
                "earlier_history_unknown" => $root->cohortEarlierUnknown($version),
            ],
            ["__aggregate_type" => "student_program_status"],
        )->withPlayhead($root->playhead() + 1);
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $before = $root->playhead();
        $root->acceptHistorical($event);
        $this->history->append(
            $this->stream($assignment->id),
            $operation,
            $before,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$event],
        );
        $this->catalogs->bind($assignment->id, new EntityId($catalog["catalog_id"]), $version);
        $this->records->project($root, $assignment->status, $expected);
        return $this->cohortResult($root, $operation, $at->format(DATE_ATOM));
    }
    /** @param array<string,mixed> $release
     * @return array<string,mixed> */
    public static function catalogSnapshot(array $release): array
    {
        $catalog = [
            "catalog_id" => $release["id"],
            "program_id" => $release["program_id"],
            "year_id" => $release["year_id"],
            "year_code" => $release["year_code"],
            "revision" => $release["revision"],
            "program_code" => $release["program_code"],
            "program_title" => $release["program_title"],
        ];
        return StudentProgramStatusSchema::catalog($catalog);
    }
    /**
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function change(
        StudentProgram $assignment,
        string $status,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        ?EntityId $replacement = null,
        ?EntityId $correlation = null,
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
        $eventClass = StudentProgramStatusWasChanged::class;
        $payload = [
            "decision_id" => $operation->value,
            "previous_decision_id" => $previous->value,
            "status" => $status,
        ];
        if ($replacement !== null) {
            $eventClass = StudentProgramWasReplaced::class;
            $payload["replacement_assignment_id"] = $replacement->value;
        }
        $event = $eventClass
        ::occur(new StudentProgramStatusId($assignment->id->value), $payload, [
                "__aggregate_type" => "student_program_status",
            ])
            ->withPlayhead($root->playhead() + 1);
        $version = $root->playhead();
        $root->acceptHistorical($event);
        $this->history->append(
            $this->stream($assignment->id),
            $operation,
            $version,
            new EventContext($actor, $at, $correlation ?? $operation, $previous, $reason),
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
            if ($stored->event instanceof \Domain\Student\Event\StudentCohortWasChanged) {
                $items[] = [
                    "kind" => "cohort",
                    "status" => null,
                    "catalog" => $p["catalog"],
                    "previous_catalog" => $p["previous_catalog"],
                    "earlier_history_unknown" => $p["earlier_history_unknown"],
                    "effective_at" => $stored->context->effectiveAt?->format(DATE_ATOM),
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                ];
                continue;
            }
            $observed = $stored->event instanceof StudentProgramWasObserved;
            $assigned = $stored->event instanceof StudentProgramWasAssigned;
            $items[] = [
                "kind" =>
                    $stored->event instanceof StudentProgramWasReplaced
                        ? "program_change"
                        : ($assigned
                            ? "assignment"
                            : ($observed
                                ? "observation"
                                : "decision")),
                "replacement_assignment_id" => $p["replacement_assignment_id"] ?? null,
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
    /**
     * @return array{root:StudentProgramStatus,receipt:?array<string,mixed>,binding:?array<string,mixed>,catalog:?array<string,mixed>}
     * @throws TypeException
     */
    private function load(
        StudentProgram $assignment,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?HistoryReadBudget $budget = null,
    ): array {
        $budget ??= new HistoryReadBudget();
        $root = StudentProgramStatus::root(new StudentProgramStatusId($assignment->id->value));
        $cursor = null;
        $receipt = null;
        do {
            $page = $budget->page($this->history, $this->stream($assignment->id), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if (
                    ($stored->event instanceof StudentProgramStatusWasChanged ||
                        $stored->event instanceof StudentProgramWasReplaced ||
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
                if (
                    $stored->event instanceof \Domain\Student\Event\StudentCohortWasChanged &&
                    $root->decision() === $operation?->value
                ) {
                    if ($stored->context->actor?->value !== $actor?->value) {
                        throw new AccessDeniedException("This cohort decision belongs to another actor.");
                    }
                    $receipt = $this->cohortResult(
                        $root,
                        $operation,
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
        $binding = $this->catalogs->binding($assignment->id);
        $release = $binding === null ? null : $this->catalogs->release(new EntityId($binding["catalog_id"]));
        if ($release !== null && !$release["published"]) {
            throw new CorruptHistoryException();
        }
        $catalog = $release === null ? null : self::catalogSnapshot($release);
        if ($catalog !== null && $catalog["program_id"] !== $assignment->programId->value) {
            throw new CorruptHistoryException();
        }
        if ($root->cohortVersion() !== null) {
            if ($root->cohort() !== $catalog || $root->cohortVersion() !== ($binding["version"] ?? -1)) {
                throw new CorruptHistoryException();
            }
        } elseif (
            ($root->assignmentSource()["cohort"] ?? null) !== null &&
            ($binding["version"] ?? -1) <= 0
        ) {
            foreach ($root->assignmentSource()["cohort"] as $field => $value) {
                if (($catalog[$field] ?? null) !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        return ["root" => $root, "receipt" => $receipt, "binding" => $binding, "catalog" => $catalog];
    }
    /** @return array<string,mixed> */
    private function cohortResult(StudentProgramStatus $root, EntityId $operation, string $at): array
    {
        return [
            "operation_id" => $operation->value,
            "assignment_id" => (string) $root->aggregateId(),
            "catalog" => $root->cohort(),
            "binding_version" => $root->cohortVersion(),
            "stream_version" => $root->playhead(),
            "effective_at" => $at,
        ];
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
    /**
     * @throws TypeException
     */
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new StudentProgramStatusId($id->value), "student_program_status");
    }
}
