<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Event\SectionMeetingPlanWasPublished;
use Domain\Registration\Repository\SectionMeetingPlanRepository;
use Domain\Registration\SectionMeetingPlan;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\FacultyConflictAssessment;
use Domain\Registration\ValueObject\SectionMeetingPlanId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class SectionMeetingPlanService
{
    public function __construct(
        private SectionMeetingPlanRepository $plans,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private PublishedSectionMeetingPlanReader $reader,
        private FacultyScheduleService $faculty,
    ) {
    }

    /** One selected section, never a roster or institution-wide publication replay.
     * @return array<string, mixed>
     */
    public function setup(EntityId $section): array
    {
        $actor = $this->authorize();
        $source = $this->plans->capture($section, $this->clock->now()->getTimezone()->getName());
        $current = $this->current($section);
        $definition = new SectionMeetingPlanDefinition($source["definition"]);
        $assessment = $this->faculty->assess($definition);
        $this->authorize($actor);
        return [
            "faculty_assessment" => $assessment->summary(),
            "working" => $definition->data,
            "pending_booking_ids" => $source["pending_booking_ids"],
            "published" => $current === null ? null : $this->describe($current),
            "changed" =>
                $current === null ||
                $definition->fingerprint() !==
                    new SectionMeetingPlanDefinition($current->event->payload()["definition"])->fingerprint(),
        ];
    }

    /** @return array<string, mixed> */
    public function review(EntityId $section, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        if (mb_strlen($reason) > 1000 || trim($reason) === "") {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a reason of up to 1000 characters."],
            ]);
        }
        new EventContext($actor, $this->clock->now(), $section, reason: $reason);
        // Capture candidates outside the transaction. Inside it, take locks before any snapshot reads.
        $candidate = $this->plans->capture($section, $this->clock->now()->getTimezone()->getName());
        return $this->transactions->run(function () use ($section, $reason, $actor, $candidate): array {
            $this->faculty->lock();
            $this->plans->lock($section, $candidate);
            $source = $this->plans->capture($section, $this->clock->now()->getTimezone()->getName());
            if (!hash_equals($candidate["revision"], $source["revision"])) {
                throw new ConcurrentModificationException(
                    "The working schedule changed. Reload it before reviewing.",
                );
            }
            $this->requireDecisions($source);
            $current = $this->current($section);
            $definition = new SectionMeetingPlanDefinition($source["definition"]);
            $assessment = $this->faculty->assess($definition);
            $this->requireFaculty($assessment);
            if (
                $current !== null &&
                $definition->fingerprint() ===
                    new SectionMeetingPlanDefinition($current->event->payload()["definition"])->fingerprint()
            ) {
                throw new ValidationException([
                    ["field" => "meetings", "message" => "This approved meeting plan is already published."],
                ]);
            }
            $review = [
                "kind" => "section-meeting-plan-review",
                "section_id" => $section->value,
                "actor_id" => $actor->value,
                "operation_id" => EntityId::generate()->value,
                "expected_version" => $current?->event->playhead() ?? 0,
                "previous_version_id" => $current?->event->payload()["version_id"],
                "definition" => $definition->data,
                "source_hash" => $source["revision"],
                "locks" => $source["locks"],
                "reason" => $reason,
                "faculty_assessment" => $assessment->data,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            $this->authorize($actor);
            return [
                "faculty_assessment" => $assessment->summary(),
                "review_token" => $this->cipher->seal(json_encode($review, JSON_THROW_ON_ERROR)),
                "definition" => $definition->data,
                "previous" => $current === null ? null : $this->describe($current),
                "reason" => $reason,
                "expires_at" => $review["expires_at"],
            ];
        });
    }

    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function publish(EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        $review = $this->decode($section, $token);
        if ($review["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This meeting-plan review belongs to another staff member.");
        }
        $operation = new EntityId($review["operation_id"]);
        return $this->transactions->run(function () use ($section, $actor, $operation, $review): array {
            $this->faculty->lock();
            $this->plans->lock($section, $review);
            [$root, $accepted] = $this->load($section, $operation);
            $this->current($section);
            $this->authorize($actor);
            if ($accepted !== null) {
                $payload = $accepted->event->payload();
                $recorded = $accepted->event->metadata()["faculty_assessment"] ?? null;
                $reviewed = $review["faculty_assessment"] ?? null;
                if (
                    $this->assessmentHash($recorded) !== $this->assessmentHash($reviewed) ||
                    $payload["previous_version_id"] !== $review["previous_version_id"] ||
                    new SectionMeetingPlanDefinition($payload["definition"])->fingerprint() !==
                        new SectionMeetingPlanDefinition($review["definition"])->fingerprint() ||
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $review["reason"] ||
                    $accepted->event->playhead() !== $review["expected_version"] + 1
                ) {
                    throw new ConcurrentModificationException("This publication operation was already used.");
                }
                return [
                    "operation_id" => $operation->value,
                    "first_version" => $accepted->event->playhead(),
                    "last_version" => $accepted->event->playhead(),
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            if (
                !isset($review["faculty_assessment"]) ||
                $review["expires_at"] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $review["expected_version"] ||
                $root->publication() !== $review["previous_version_id"]
            ) {
                throw new ConcurrentModificationException(
                    "The publication or review changed. Review the meeting plan again.",
                );
            }
            $source = $this->plans->capture($section, $this->clock->now()->getTimezone()->getName());
            $this->requireDecisions($source);
            if (
                !hash_equals($review["source_hash"], $source["revision"]) ||
                new SectionMeetingPlanDefinition($review["definition"])->fingerprint() !==
                    new SectionMeetingPlanDefinition($source["definition"])->fingerprint()
            ) {
                throw new ConcurrentModificationException(
                    "The approved meetings or offering changed. Review the meeting plan again.",
                );
            }
            $assessment = $this->reviewedFaculty($review);
            $event = SectionMeetingPlanWasPublished::occur(
                $this->stream($section)->aggregateId,
                [
                    "version_id" => $operation->value,
                    "previous_version_id" => $review["previous_version_id"],
                    "definition" => $review["definition"],
                ],
                ["__aggregate_type" => "section_meeting_plan", "faculty_assessment" => $assessment->data],
            )->withPlayhead($root->playhead() + 1);
            $receipt = $this->history->append(
                $this->stream($section),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: $review["reason"]),
                [$event],
            );
            $root->acceptHistorical($event);
            $this->plans->retain(
                $section,
                $operation,
                $root->playhead(),
                new SectionMeetingPlanDefinition($review["definition"])->fingerprint(),
            );
            $this->audit->record($actor, "section.meeting_plan_published", $section);
            $this->authorize($actor);
            $fresh = $this->plans->capture($section, $this->clock->now()->getTimezone()->getName());
            $published = $this->current($section);
            if (
                $review["expires_at"] <= $this->clock->now()->getTimestamp() ||
                !hash_equals($review["source_hash"], $fresh["revision"]) ||
                $published?->event->payload()["version_id"] !== $operation->value ||
                $published->event->playhead() !== $review["expected_version"] + 1
            ) {
                throw new ConcurrentModificationException(
                    "The meeting plan changed while publishing. Review again.",
                );
            }
            $this->reviewedFaculty($review);
            return $receipt;
        });
    }

    /** Frozen, small pages for authorized academic staff. Working bookings are never replayed.
     * @return array<string, mixed>
     */
    public function versions(EntityId $section, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        $this->current($section);
        $page = $this->history->page($this->stream($section), $cursor, 5);
        $items = array_map($this->describe(...), $page->events);
        $this->authorize($actor);
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "through_version" => $page->throughVersion,
        ];
    }

    private function current(EntityId $section): ?StoredEvent
    {
        return $this->reader->read($section);
    }

    private function assessmentHash(?array $data = null): ?string
    {
        return $data === null ? null : new FacultyConflictAssessment($data)->fingerprint();
    }

    private function requireFaculty(FacultyConflictAssessment $assessment): void
    {
        if ($assessment->blocked()) {
            throw new ValidationException([
                [
                    "field" => "meetings",
                    "message" =>
                        implode("", [
                    "A faculty member has overlapping published classes. ",
                    "Review the faculty conflicts and resolve the schedule before publishing.",
                    ]),
                ],
            ]);
        }
    }

    private function reviewedFaculty(array $review): FacultyConflictAssessment
    {
        $current = $this->faculty->assess(new SectionMeetingPlanDefinition($review["definition"]));
        if ($this->assessmentHash($review["faculty_assessment"]) !== $current->fingerprint()) {
            throw new ConcurrentModificationException(
                "The faculty schedule or policy changed. Review the meeting plan again.",
            );
        }
        $this->requireFaculty($current);
        return $current;
    }

    /** @return array{SectionMeetingPlan, ?StoredEvent} */
    private function load(EntityId $section, EntityId $operation): array
    {
        $root = SectionMeetingPlan::root($this->stream($section)->aggregateId);
        $accepted = $cursor = null;
        do {
            $page = $this->history->page($this->stream($section), $cursor, 5);
            if ($page->throughVersion > 1000) {
                throw new InvalidArgumentException(
                    "This meeting-plan history requires an operator review before another publication.",
                );
            }
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($stored->event->payload()["version_id"] === $operation->value) {
                    if ($accepted !== null || $stored->context->correlation?->value !== $operation->value) {
                        throw new CorruptHistoryException();
                    }
                    $accepted = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return [$root, $accepted];
    }

    /** @return array<string, mixed> */
    private function decode(EntityId $section, #[\SensitiveParameter] string $token): array
    {
        try {
            if (strlen($token) > 1048576) {
                throw new InvalidArgumentException();
            }
            $r = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            $fields = [
                "kind",
                "section_id",
                "actor_id",
                "operation_id",
                "expected_version",
                "previous_version_id",
                "definition",
                "source_hash",
                "locks",
                "reason",
                "expires_at",
            ];
            if (is_array($r) && array_key_exists("faculty_assessment", $r)) {
                $fields[] = "faculty_assessment";
                new FacultyConflictAssessment($r["faculty_assessment"]);
            }
            $keys = is_array($r) ? array_keys($r) : [];
            sort($fields);
            sort($keys);
            if (
                $fields !== $keys ||
                $r["kind"] !== "section-meeting-plan-review" ||
                $r["section_id"] !== $section->value ||
                !is_int($r["expected_version"]) ||
                $r["expected_version"] < 0 ||
                !is_int($r["expires_at"]) ||
                !is_array($r["definition"]) ||
                !is_string($r["reason"]) ||
                !is_string($r["source_hash"]) ||
                preg_match('/^[a-f0-9]{64}$/D', $r["source_hash"]) !== 1 ||
                !is_array($r["locks"]) ||
                array_keys($r["locks"]) !== ["rooms", "bookings", "buildings"]
            ) {
                throw new InvalidArgumentException();
            }
            foreach (["actor_id", "operation_id"] as $field) {
                new EntityId($r[$field]);
            }
            if ($r["previous_version_id"] !== null) {
                new EntityId($r["previous_version_id"]);
            }
            foreach ($r["locks"] as $ids) {
                if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) {
                    throw new InvalidArgumentException();
                }
                foreach ($ids as $id) {
                    new EntityId($id);
                }
            }
            new SectionMeetingPlanDefinition($r["definition"]);
            return $r;
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this meeting plan again before publishing.");
        }
    }

    /** @param array<string, mixed> $source */
    private function requireDecisions(array $source): void
    {
        if ($source["pending_booking_ids"] !== []) {
            throw new ValidationException([
                [
                    "field" => "meetings",
                    "message" =>
                        "Approve or cancel the pending class meeting requests before publishing this plan.",
                ],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function describe(StoredEvent $stored): array
    {
        if (!($stored->event instanceof SectionMeetingPlanWasPublished)) {
            throw new CorruptHistoryException();
        }
        return [
            "faculty_assessment" => isset($stored->event->metadata()["faculty_assessment"])
                ? new FacultyConflictAssessment($stored->event->metadata()["faculty_assessment"])->summary()
                : null,
            "publication_id" => $stored->event->payload()["version_id"],
            "version" => $stored->event->playhead(),
            "definition" => new SectionMeetingPlanDefinition($stored->event->payload()["definition"])->data,
            "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            "reason" => $stored->context->reason,
        ];
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:scheduling:manage");
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException("Access denied.");
        }
        return $actor;
    }

    private function stream(EntityId $section): StreamReference
    {
        return new StreamReference(new SectionMeetingPlanId($section->value), "section_meeting_plan");
    }
}
