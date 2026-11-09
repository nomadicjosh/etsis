<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Course;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Registration\Enrollment;
use Domain\Registration\EnrollmentHistory;
use Domain\Registration\Event\EnrollmentWasEstablished;
use Domain\Registration\Event\EnrollmentWasClosed;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal ledger: registration/drop services own current authorization and student/person/section locks. */
final readonly class EnrollmentLedger
{
    public function __construct(
        private EnrollmentHistoryRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }
    /** @param array<string,mixed> $eligibility */
    public function accept(
        Enrollment $enrollment,
        Section $section,
        Course $course,
        #[\SensitiveParameter] array $eligibility,
        EntityId $actor,
    ): void {
        $this->actor($actor);
        if (
            $enrollment->sectionId->value !== $section->id->value ||
            $enrollment->termId->value !== $section->termId->value ||
            $course->id->value !== $section->courseId->value
        ) {
            throw new CorruptHistoryException();
        }
        $seat = $this->nextSeat($section, 1);
        $event = EnrollmentWasEstablished::occur(
            new EnrollmentHistoryId($enrollment->id->value),
            [
                "decision_id" => $enrollment->id->value,
                "provenance" => "registered",
                "enrollment" => EnrollmentFacts::fromEnrollment($enrollment)->facts,
                "source" => $this->source($section, $enrollment->termId, $course->publicationVersionId),
                "eligibility" => $eligibility,
                "seat" => $seat,
            ],
            ["__aggregate_type" => "enrollment"],
        )->withPlayhead(1);
        $this->history->append(
            $this->stream($enrollment->id),
            $enrollment->id,
            0,
            new EventContext(
                $actor,
                $enrollment->registeredAt,
                $enrollment->id,
                reason: "Registration accepted after eligibility and seat checks.",
            ),
            [$event],
        );
        $root = EnrollmentHistory::root(new EnrollmentHistoryId($enrollment->id->value));
        $root->acceptHistorical($event);
        $this->project($root, null);
    }
    public function verifyExisting(Enrollment $enrollment, Section $section): void
    {
        $this->load($enrollment->id);
        $this->head($section);
    }
    /** @param array<string,mixed> $outcome */
    public function close(Enrollment $closed, Section $section, array $outcome, EventContext $context): void
    {
        if ($context->actor === null || $context->correlation === null) {
            throw new \InvalidArgumentException("A trusted registration context is required.");
        }
        $this->actor($context->actor);
        $root = $this->load($closed->id);
        $before = $this->records->current($closed->id) ?? throw new CorruptHistoryException();
        if ($root->playhead() === 0) {
            $op = PublishedGradeId::forVersion(
                $context->correlation,
                "registration.observe." . $closed->id->value,
            );
            $base = EnrollmentWasEstablished::occur(
                new EnrollmentHistoryId($closed->id->value),
                [
                    "decision_id" => $op->value,
                    "provenance" => "observed_existing",
                    "enrollment" => $before,
                    "source" => $this->source($section, $closed->termId, null),
                    "eligibility" => null,
                    "seat" => null,
                ],
                ["__aggregate_type" => "enrollment"],
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($closed->id),
                $op,
                0,
                new EventContext(
                    $context->actor,
                    $this->clock->now(),
                    $context->correlation,
                    reason: "Existing enrollment observed before its reviewed closure; " .
                        "earlier decision context is unknown.",
                ),
                [$base],
            );
            $root->acceptHistorical($base);
        }
        $operation = PublishedGradeId::forVersion(
            $context->correlation,
            "registration.close." . $closed->id->value,
        );
        $event = EnrollmentWasClosed::occur(
            new EnrollmentHistoryId($closed->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "status" => $closed->status->value,
                "status_at" => EnrollmentFacts::utc($closed->statusAt),
                "policy" => [
                    "grace_ends_on" => $outcome["grace_ends_on"],
                    "version" => $outcome["policy_version"],
                    "remove_current" => $outcome["delete_records"],
                ],
                "seat" => $this->nextSeat($section, -1),
            ],
            ["__aggregate_type" => "enrollment"],
        )->withPlayhead($root->playhead() + 1);
        $this->history->append(
            $this->stream($closed->id),
            $operation,
            $root->playhead(),
            new EventContext(
                $context->actor,
                $context->effectiveAt,
                $context->correlation,
                new EntityId($root->decision() ?? throw new CorruptHistoryException()),
                $context->reason,
            ),
            [$event],
        );
        $root->acceptHistorical($event);
        if (!$this->same($root->facts(), EnrollmentFacts::fromEnrollment($closed)->facts)) {
            throw new CorruptHistoryException();
        }
        $this->project($root, $before["version"]);
    }
    /** Restricted curated read, after caller authorizes and locks the student/section.
     * @return array<string,mixed> */
    public function summary(EntityId $id): array
    {
        $root = $this->load($id);
        $f = $root->facts() ?? throw new CorruptHistoryException();
        $s = $root->source() ?? throw new CorruptHistoryException();
        $page = $this->history->page($this->stream($id), null, 3);
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            $p = $e->payload();
            $items[] = [
                "action" =>
                    $e instanceof EnrollmentWasEstablished
                        ? $p["provenance"]
                        : ($p["policy"]["remove_current"]
                            ? "early_drop"
                            : "late_drop"),
                "section_sequence" => $p["seat"]["sequence"] ?? null,
                "effective_at" => $stored->context->describe()["effective_at"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "enrollment_id" => $id->value,
            "student_id" => $f["student_id"],
            "section_id" => $f["section_id"],
            "section_code" => $s["section_code"],
            "term_code" => $s["term_code"],
            "status" => $f["status"],
            "current_records_removed" => $f["status"] === "D",
            "earlier_history_unknown" => $root->provenance() === "observed_existing",
            "items" => $items,
        ];
    }
    private function load(EntityId $id): EnrollmentHistory
    {
        $root = EnrollmentHistory::root(new EnrollmentHistoryId($id->value));
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $f = $root->facts();
        $m = $this->records->mapping($id);
        if ($f === null) {
            if ($m !== null) {
                throw new CorruptHistoryException();
            }
            return $root;
        }
        $seat = $root->seat() ?? throw new CorruptHistoryException();
        if (
            $m === null ||
            count($m) !== 5 ||
            $m["student_id"] !== $f["student_id"] ||
            $m["section_id"] !== $f["section_id"] ||
            $m["term_id"] !== $f["term_id"] ||
            $m["last_sequence"] !== $seat["sequence"]
        ) {
            throw new CorruptHistoryException();
        }
        $current = $this->records->current($id);
        if ($f["status"] === "D" ? $current !== null : !$this->same($f, $current)) {
            throw new CorruptHistoryException();
        }
        $indices = $this->records->decisions($id);
        if (count($indices) !== count($root->decisions())) {
            throw new CorruptHistoryException();
        }
        foreach ($root->decisions() as $i => $d) {
            if (!$this->same($indices[$i], $d)) {
                throw new CorruptHistoryException();
            }
        }
        return $root;
    }
    /** @return array<string,mixed>|null */
    private function head(Section $section): ?array
    {
        $last = $this->records->latest($section->id);
        $maximum = $this->records->maximumSequence($section->id);
        if ($last === null) {
            if ($maximum !== 0) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        if ($last["sequence"] !== $maximum) {
            throw new CorruptHistoryException();
        }
        $root = $this->load(new EntityId($last["enrollment_id"]));
        $seat = $root->seat();
        if (
            $root->decision() !== $last["id"] ||
            $root->playhead() !== $last["stream_version"] ||
            ($root->facts() ?? throw new CorruptHistoryException())["section_id"] !== $section->id->value ||
            $seat === null ||
            $seat["sequence"] !== $last["sequence"] ||
            $seat["used_after"] !== $this->records->usedSeats($section->id)
        ) {
            throw new CorruptHistoryException();
        }
        return $last;
    }
    /** @return array<string,mixed> */
    private function nextSeat(Section $section, int $delta): array
    {
        $last = $this->head($section);
        $before = $this->records->usedSeats($section->id);
        if (
            ($delta === 1 && ($before >= $section->capacity || !$section->active)) ||
            ($delta === -1 && $before < 1)
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                "The section no longer has the reviewed seat availability.",
            );
        }
        return [
            "sequence" => ($last["sequence"] ?? 0) + 1,
            "previous_decision_id" => $last["id"] ?? null,
            "used_before" => $before,
            "used_after" => $before + $delta,
            "capacity" => $section->capacity,
        ];
    }
    /** @return array<string,mixed> */
    private function source(Section $s, EntityId $term, ?EntityId $courseVersion): array
    {
        return [
            "course_id" => $s->courseId->value,
            "course_version_id" => $courseVersion?->value,
            "section_code" => $s->code,
            "term_code" => $this->records->termCode($term),
            "capacity" => $s->capacity,
            "active" => $s->active,
            "web_registration" => $s->webRegistration,
            "fee_minor" => $s->fee->minor,
            "currency" => $s->fee->currency,
            "delivery_mode" => $s->deliveryMode->value,
            "faculty_user_id" => $s->facultyUserId?->value,
        ];
    }
    private function project(EnrollmentHistory $root, ?int $expectedVersion): void
    {
        $f = $root->facts() ?? throw new CorruptHistoryException();
        $seat = $root->seat() ?? throw new CorruptHistoryException();
        $this->records->project($f, $seat["sequence"], $expectedVersion);
        $last = $root->decisions();
        $this->records->index($last[count($last) - 1]);
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new EnrollmentHistoryId($id->value), "enrollment");
    }
    private function actor(EntityId $actor): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The registration actor changed.");
        }
    }
    /** @param array<string,mixed>|null $a
     * @param array<string,mixed>|null $b */
    private function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $v) {
            if (!array_key_exists($key, $b) || $b[$key] !== $v) {
                return false;
            }
        }
        return true;
    }
}
