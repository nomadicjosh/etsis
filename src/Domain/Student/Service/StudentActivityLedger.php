<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentActivityWasObserved;
use Domain\Student\Event\StudentActivityWasDecided;
use Domain\Student\Repository\StudentActivityRepository;
use Domain\Student\Student;
use Domain\Student\StudentActivity;
use Domain\Student\ValueObject\StudentActivityId;
use Domain\Student\ValueObject\StudentActivitySchema;

/** Internal ledger: entry-point services authorize, lock the student and own the atomic unit of work. */
final readonly class StudentActivityLedger
{
    public function __construct(
        private StudentActivityRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    public function verify(Student $student): int
    {
        return $this->load($student)->playhead();
    }
    /** Called only for a genuinely new student inside the matriculation transaction. */
    public function start(
        Student $student,
        EntityId $application,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): void {
        if ($this->access->authenticatedUserId()->value !== $actor->value || !$student->active) {
            throw new AccessDeniedException("The initial activity actor or state changed.");
        }
        $root = $this->load($student);
        if ($root->playhead() !== 0) {
            throw new CorruptHistoryException();
        }
        $decision = PublishedGradeId::forVersion($operation, "matriculation.activity");
        $e = \Domain\Student\Event\StudentActivityWasStarted::occur(
            new StudentActivityId($student->id->value),
            [
                "decision_id" => $decision->value,
                "student_id" => $student->id->value,
                "person_id" => $student->personId->value,
                "active" => true,
                "effective_on" => $this->clock->now()->format("Y-m-d"),
                "application_id" => $application->value,
            ],
            ["__aggregate_type" => "student_activity"],
        )->withPlayhead(1);
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($student->id),
            $decision,
            0,
            new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
            [$e],
        );
        $this->records->project($student->id, $root, true, 0);
    }
    /** @return array<string,mixed> */
    public function preview(Student $student, bool $active, string $on): array
    {
        $this->date($on);
        $root = $this->load($student);
        $at = $this->replay($student->id, $on);
        $before = $at->active();
        if ($root->playhead() === 0 && $on === $this->clock->now()->format("Y-m-d")) {
            $before = $student->active;
        }
        $version = $root->playhead();
        $this->propose($root, $student, EntityId::generate(), $active, $on, "registrar", null);
        return [
            "version" => $version,
            "current_active" => $student->active,
            "active_on_date" => $before,
            "requested_active" => $active,
            "resulting_active" => $root->active(),
            "effective_on" => $on,
            "earlier_history_unknown" => $root->earlierHistoryUnknown(),
        ];
    }

    /** @return array<string,mixed> */
    public function decide(
        Student $student,
        bool $active,
        string $on,
        string $source,
        ?EntityId $reference,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        bool $requireCurrentTarget = false,
    ): array {
        $this->date($on);
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The activity actor changed.");
        }
        $root = $this->load($student);
        $expected = $root->playhead();
        $events = $this->propose($root, $student, $operation, $active, $on, $source, $reference);
        if ($requireCurrentTarget && $root->active() !== $active) {
            throw new ValidationException([
                [
                    "field" => "activity",
                    "message" =>
                        "A later activity decision controls the current status. Review Activity history; " .
                        "record this case without changing activity or review a separate activity decision.",
                ],
            ]);
        }
        foreach ($events as $event) {
            $observation = $event instanceof StudentActivityWasObserved;
            $this->history->append(
                $this->stream($student->id),
                new EntityId($event->payload()["decision_id"]),
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    $observation
                        ? $this->clock->now()
                        : new DateTimeImmutable($on, $this->clock->now()->getTimezone()),
                    $operation,
                    $observation ? null : new EntityId($event->payload()["previous_decision_id"]),
                    $observation
                        ? "Current activity observed before a reviewed decision; earlier history is unknown."
                        : $reason,
                ),
                [$event],
            );
        }
        $this->records->project($student->id, $root, $student->active, $expected);
        return $this->result($root, $operation, $active, $on);
    }

    /** Exact retries return the outcome at acceptance, never reproject an earlier decision.
     * @return array<string,mixed>|null */
    public function receipt(Student $student, EntityId $operation, EntityId $actor): ?array
    {
        $this->load($student);
        $root = StudentActivity::root(new StudentActivityId($student->id->value));
        $cursor = null;
        $found = null;
        do {
            $page = $this->history->page($this->stream($student->id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                if (
                    $stored->event instanceof StudentActivityWasDecided &&
                    $p["decision_id"] === $operation->value
                ) {
                    if ($stored->context->actor?->value !== $actor->value) {
                        throw new AccessDeniedException("This receipt belongs to another actor.");
                    }
                    $found = $this->result($root, $operation, $p["active"], $p["effective_on"]);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $found;
    }
    /** Curated staff read; freeform reasons and raw event/context fields are deliberately excluded.
     * @return array<string,mixed> */
    public function timeline(Student $student, ?string $cursor): array
    {
        $root = $this->load($student);
        $page = $this->history->page($this->stream($student->id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "kind" =>
                    $stored->event instanceof \Domain\Student\Event\StudentActivityWasStarted
                        ? "initial"
                        : ($stored->event instanceof StudentActivityWasObserved
                            ? "observation"
                            : "decision"),
                "active" => $p["active"],
                "effective_on" => $p["effective_on"],
                "source" =>
                    $p["source"] ??
                    ($stored->event instanceof \Domain\Student\Event\StudentActivityWasStarted
                        ? "matriculation"
                        : "observed_existing"),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "active" => $student->active,
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" => $root->earlierHistoryUnknown(),
            "today" => $this->clock->now()->format("Y-m-d"),
        ];
    }

    private function load(Student $student): StudentActivity
    {
        $current = $this->records->current($student->id);
        $root = $this->replay($student->id);
        $marker = $this->records->marker($student->id);
        if (
            $current["person_id"] !== $student->personId->value ||
            $current["active"] !== $student->active ||
            ($root->playhead() === 0
                ? $marker !== null
                : $root->person() !== $student->personId->value ||
                    $root->active() !== $student->active ||
                    $marker !== $root->marker())
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    private function replay(EntityId $id, ?string $cutoff = null): StudentActivity
    {
        $root = StudentActivity::root(new StudentActivityId($id->value));
        if ($cutoff !== null) {
            $root->effectiveThrough($cutoff);
        }
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $root;
    }
    /** @return list<\Codefy\Domain\EventSourcing\DomainEvent> */
    private function propose(
        StudentActivity $root,
        Student $student,
        EntityId $operation,
        bool $active,
        string $on,
        string $source,
        ?EntityId $reference,
    ): array {
        $id = new StudentActivityId($student->id->value);
        $events = [];
        if ($root->playhead() === 0) {
            $e = StudentActivityWasObserved::occur(
                $id,
                [
                    "decision_id" => PublishedGradeId::forVersion($operation, "activity.observation")->value,
                    "student_id" => $student->id->value,
                    "person_id" => $student->personId->value,
                    "active" => $student->active,
                    "effective_on" => $this->clock->now()->format("Y-m-d"),
                ],
                ["__aggregate_type" => "student_activity"],
            )->withPlayhead(1);
            $root->acceptHistorical($e);
            $events[] = $e;
        }
        $e = StudentActivityWasDecided::occur(
            $id,
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "student_id" => $student->id->value,
                "active" => $active,
                "effective_on" => $on,
                "source" => $source,
                "reference_id" => $reference?->value,
            ],
            ["__aggregate_type" => "student_activity"],
        )->withPlayhead($root->playhead() + 1);
        $root->acceptHistorical($e);
        $events[] = $e;
        return $events;
    }
    private function date(string $on): void
    {
        if (!StudentActivitySchema::date($on) || $on > $this->clock->now()->format("Y-m-d")) {
            throw new ValidationException([
                ["field" => "effective_on", "message" => "Choose a valid date today or earlier."],
            ]);
        }
    }
    /** @return array<string,mixed> */
    private function result(StudentActivity $root, EntityId $operation, bool $active, string $on): array
    {
        return [
            "operation_id" => $operation->value,
            "effective_on" => $on,
            "requested_active" => $active,
            "resulting_active" => $root->active(),
            "version" => $root->playhead(),
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new StudentActivityId($id->value), "student_activity");
    }
}
