<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Event\SectionOfferingVersionWasAmended;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\SectionOfferingBinding;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Change the reference for future registrations only; never rewrite existing enrollments or charges. */
final readonly class SectionOfferingAmendmentService
{
    public function __construct(
        private SectionPublicationService $publications,
        private SectionVersionRepository $versions,
        private CatalogRevisionRepository $catalog,
        private EnrollmentHistoryRepository $enrollments,
        private RegistrationRepository $registration,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(
        EntityId $section,
        EntityId $publication,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (mb_strlen($reason) > 1000) {
                throw new InvalidArgumentException();
            }
            new EventContext($actor, $this->clock->now(), $section, reason: $reason);
        } catch (InvalidArgumentException) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a valid reason of up to 1000 characters."],
            ]);
        }
        return $this->transactions->run(function () use ($section, $publication, $reason, $actor): array {
            $this->lock($section, $actor);
            [$root, $before] = $this->load($section);
            if ($before->publication->value === $publication->value) {
                throw new ValidationException([
                    ["field" => "version_id", "message" => "Choose a different published offering version."],
                ]);
            }
            $context = $this->publications->offeringContext($section, $publication);
            $after = $context["selected"];
            $this->owners($before, $after);
            $r = [
                "kind" => "section-offering-amendment.v1",
                "section_id" => $section->value,
                "actor_id" => $actor->value,
                "operation_id" => EntityId::generate()->value,
                "expected_version" => $root->playhead(),
                "previous_publication_id" => $before->publication->value,
                "publication_id" => $publication->value,
                "before_hash" => $before->fingerprint(),
                "definition_hash" => $after->fingerprint(),
                "source_hash" => $context["source_hash"],
                "enrolled" => $this->registration->sectionCount($section),
                "seat_sequence" => $this->enrollments->maximumSequence($section),
                "reason" => $reason,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            return $r + [
                "previous" => $before->describe(),
                "selected" => $after->describe(),
                "faculty_label" => $context["faculty_label"],
                "review_token" => $this->cipher->seal(json_encode($r, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function commit(EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 32768) {
                throw new InvalidArgumentException();
            }
            $r = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($r) ||
                ($r["kind"] ?? null) !== "section-offering-amendment.v1" ||
                ($r["section_id"] ?? null) !== $section->value ||
                !is_int($r["expected_version"] ?? null) ||
                $r["expected_version"] < 1 ||
                !is_int($r["expires_at"] ?? null) ||
                !is_int($r["enrolled"] ?? null) ||
                !is_int($r["seat_sequence"] ?? null) ||
                !is_string($r["reason"] ?? null) ||
                !is_string($r["source_hash"] ?? null) ||
                !is_string($r["before_hash"] ?? null) ||
                !is_string($r["definition_hash"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            $operation = new EntityId($r["operation_id"]);
            $publication = new EntityId($r["publication_id"]);
            new EntityId($r["previous_publication_id"]);
        } catch (Throwable) {
            throw new InvalidArgumentException("Review the future offering amendment again.");
        }
        if (($r["actor_id"] ?? null) !== $actor->value) {
            throw new AccessDeniedException("This offering review belongs to another staff member.");
        }
        return $this->transactions->run(function () use (
            $section,
            $operation,
            $publication,
            $r,
            $actor,
        ): array {
            $this->lock($section, $actor);
            [$root, $before, $accepted] = $this->load($section, $operation);
            $payload = [
                "previous_publication_id" => $r["previous_publication_id"],
                "publication_id" => $publication->value,
                "definition_hash" => $r["definition_hash"],
            ];
            if ($accepted !== null) {
                $p = $accepted->event->payload();
                if (
                    !($accepted->event instanceof SectionOfferingVersionWasAmended) ||
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $r["reason"] ||
                    $accepted->event->playhead() !== $r["expected_version"] + 1 ||
                    count($p) !== count($payload) ||
                    array_diff_assoc($p, $payload) !== []
                ) {
                    throw new ConcurrentModificationException("This amendment operation was already used.");
                }
                $position = $accepted->event->playhead();
                return [
                    "operation_id" => $operation->value,
                    "first_version" => $position,
                    "last_version" => $position,
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            if (
                $r["expires_at"] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $r["expected_version"] ||
                $before->publication->value !== $r["previous_publication_id"] ||
                !hash_equals($before->fingerprint(), $r["before_hash"]) ||
                $this->registration->sectionCount($section) !== $r["enrolled"] ||
                $this->enrollments->maximumSequence($section) !== $r["seat_sequence"]
            ) {
                throw new ConcurrentModificationException(
                    "The offering or registrations changed. Review the amendment again.",
                );
            }
            $context = $this->publications->offeringContext($section, $publication);
            $after = $context["selected"];
            $this->owners($before, $after);
            if (
                !hash_equals($after->fingerprint(), $r["definition_hash"]) ||
                !hash_equals($context["source_hash"], $r["source_hash"])
            ) {
                throw new ConcurrentModificationException(
                    "The offering details changed. Review the amendment again.",
                );
            }
            $event = SectionOfferingVersionWasAmended::occur(
                SectionOfferingSelection::stream($section)->aggregateId,
                $payload,
                ["__aggregate_type" => "section_offering_binding"],
            )->withPlayhead($root->playhead() + 1);
            $receipt = $this->history->append(
                SectionOfferingSelection::stream($section),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: $r["reason"]),
                [$event],
            );
            $this->versions->amend($before, $after, $root->playhead());
            $this->audit->record($actor, "section.offering_version_amended", $section);
            return $receipt;
        });
    }

    /** Curated history only; protected reasons and raw event envelopes are never returned.
     * @return array<string, mixed>
     */
    public function history(EntityId $section, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->catalog->get("sections", $section);
        $page = $this->history->page(SectionOfferingSelection::stream($section), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            $event = $stored->event;
            $p = $event->payload();
            $selected =
            $this->versions->find(new EntityId($p["publication_id"])) ??
            throw new CorruptHistoryException();
            if (
                $selected->section->value !== $section->value ||
                !hash_equals($selected->fingerprint(), $p["definition_hash"])
            ) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "action" => $event instanceof SectionOfferingVersionWasAmended ? "amended" : "selected",
                "version" => $event->playhead(),
                "selected" => $selected->describe(),
                "previous_publication_id" => $p["previous_publication_id"] ?? null,
                "effective_at" => $stored->context->describe()["effective_at"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "through_version" => $page->throughVersion,
        ];
    }

    private function lock(EntityId $section, EntityId $actor): void
    {
        $this->catalog->lock("sections", $section);
        $row = $this->catalog->get("sections", $section);
        $this->catalog->lock("courses", new EntityId((string) $row["course_id"]));
        $this->catalog->lock("terms", new EntityId((string) $row["term_id"]));
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }

    /** @return array{SectionOfferingBinding, PublishedSectionDefinition, ?StoredEvent} */
    private function load(EntityId $section, ?EntityId $operation = null): array
    {
        $stream = SectionOfferingSelection::stream($section);
        $root = SectionOfferingBinding::root($stream->aggregateId);
        $cursor = null;
        $accepted = null;
        do {
            $page = $this->history->page($stream, $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($operation !== null && $stored->context->correlation?->value === $operation->value) {
                    if ($accepted !== null) {
                        throw new CorruptHistoryException();
                    }
                    $accepted = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $current = SectionOfferingSelection::verify($section, $this->versions, $this->history);
        if ($current === null) {
            throw new ValidationException([
                ["field" => "version_id", "message" => "Select an initial offering version first."],
            ]);
        }
        if (
            $root->playhead() !== $this->versions->selectionPosition($section) ||
            $root->publication() !== $current->publication->value
        ) {
            throw new CorruptHistoryException();
        }
        return [$root, $current, $accepted];
    }

    private function owners(PublishedSectionDefinition $before, PublishedSectionDefinition $after): void
    {
        if (
            $before->section->value !== $after->section->value ||
            $before->publication->value === $after->publication->value
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["course_id", "course_version_id", "term_id"] as $field) {
            if ($before->definition->data[$field] !== $after->definition->data[$field]) {
                throw new CorruptHistoryException();
            }
        }
    }
}
