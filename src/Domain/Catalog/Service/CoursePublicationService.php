<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\CoursePublication;
use Domain\Catalog\Event\CourseDefinitionWasPublished;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class CoursePublicationService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private CatalogWorkflowRepository $relationships,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private \Domain\Catalog\Repository\CourseTextProjection $textProjection,
        private \Domain\Catalog\Repository\SubjectRepository $subjects,
        private \Domain\Catalog\Repository\ClassificationRepository $classifications,
    ) {
    }

    /** Authorized, consistent saved-draft snapshot. This does not publish or replay prior definitions. */
    public function workingDefinition(EntityId $course): CourseDefinition
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($course, $actor): CourseDefinition {
            $this->catalog->lock("courses", $course);
            $this->authorize($actor);
            $definition = $this->definition($course);
            $this->authorize($actor);
            return $definition;
        });
    }

    /** @return array<string, mixed> */
    public function review(EntityId $course, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a publication reason of at most 1000 characters.");
        }
        return $this->transactions->run(function () use ($course, $actor, $reason): array {
            $this->catalog->lock("courses", $course);
            $this->authorize($actor);
            $definition = $this->definition($course)->describe();
            [$publication] = $this->load($course);
            $this->authorize($actor);
            $operation = EntityId::generate();
            new EventContext($actor, $this->clock->now(), $operation, reason: $reason);
            $data = [
                "kind" => "course-publication-review",
                "course_id" => $course->value,
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $publication->playhead(),
                "previous_version_id" => $publication->versionId(),
                "definition" => $definition,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
                "reason" => $reason,
            ];
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }

    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function publish(EntityId $course, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 524288) {
                throw new InvalidArgumentException();
            }
            $review = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($review) ||
                ($review["kind"] ?? null) !== "course-publication-review" ||
                ($review["course_id"] ?? null) !== $course->value ||
                !is_string($review["actor_id"] ?? null) ||
                !is_string($review["operation_id"] ?? null) ||
                !is_int($review["expected_version"] ?? null) ||
                $review["expected_version"] < 0 ||
                !array_key_exists("previous_version_id", $review) ||
                !is_string($review["reason"] ?? null) ||
                !is_array($review["definition"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            $operation = new EntityId($review["operation_id"]);
            if ($review["previous_version_id"] !== null) {
                new EntityId($review["previous_version_id"]);
            }
            if (!is_int($review["expires_at"] ?? null) || array_key_exists("effective_at", $review)) {
                throw new InvalidArgumentException();
            }
            CourseDefinition::fromArray($review["definition"]);
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this course again before publishing.");
        }
        if ($review["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This publication review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($course, $actor, $review, $operation): array {
            $this->catalog->lock("courses", $course);
            $this->authorize($actor);
            [$root, $accepted] = $this->load($course, $operation);
            $payload = [
                "version_id" => $operation->value,
                "previous_version_id" => $review["previous_version_id"],
                "definition" => $review["definition"],
            ];
            if ($accepted !== null) {
                if (
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $review["reason"] ||
                    $accepted->event->playhead() !== $review["expected_version"] + 1 ||
                    $this->fingerprint($accepted->event->payload()) !== $this->fingerprint($payload)
                ) {
                    throw new ConcurrentModificationException("This publication operation was already used.");
                }
                $this->authorize($actor);
                $position = $accepted->event->playhead();
                return [
                    "operation_id" => $operation->value,
                    "first_version" => $position,
                    "last_version" => $position,
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            if (
                $review["expires_at"] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $review["expected_version"] ||
                $root->versionId() !== $review["previous_version_id"] ||
                $this->definition($course)->describe() !== $review["definition"]
            ) {
                throw new ConcurrentModificationException(
                    "The course or review changed. Review it again before publishing.",
                );
            }
            $this->authorize($actor);
            $event = CourseDefinitionWasPublished::occur($this->stream($course)->aggregateId, $payload, [
                "__aggregate_type" => "course_publication",
                "schema_version" => 1,
            ])->withPlayhead($root->playhead() + 1);
            $receipt = $this->history->append(
                $this->stream($course),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: $review["reason"]),
                [$event],
            );
            $this->textProjection->apply(
                $course,
                CourseText::fromArray($review["definition"]),
                $operation,
                $receipt["last_version"],
            );
            $this->audit->record($actor, "course.definition_published", $course);
            return $receipt;
        });
    }

    /** Curated catalog definitions only; never return raw event metadata, actors or protected reasons.
     * @return array<string, mixed>
     */
    public function versions(EntityId $course, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->catalog->get("courses", $course);
        $page = $this->history->page($this->stream($course), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            if (!($stored->event instanceof CourseDefinitionWasPublished)) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $payload = $stored->event->payload();
            $items[] = [
                "version_id" => $payload["version_id"],
                "version" => $stored->event->playhead(),
                "definition" => $payload["definition"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "effective_at" => $stored->context->describe()["effective_at"],
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "through_version" => $page->throughVersion,
        ];
    }

    /** A bounded scan verifies the selected version against the protected publication stream. */
    public function publishedDefinition(
        EntityId $course,
        EntityId $version,
    ): \Domain\Catalog\ValueObject\PublishedCourseDefinition {
        $found = null;
        $cursor = null;
        do {
            $page = $this->versions($course, $cursor);
            foreach ($page["items"] as $item) {
                if ($item["version_id"] === $version->value) {
                    $found = \Domain\Catalog\ValueObject\PublishedCourseDefinition::fromPublication(
                        $course,
                        $item,
                    );
                }
            }
            $cursor = $page["next_cursor"];
        } while ($cursor !== null);
        return $found ??
        throw new \Domain\Shared\Exception\RecordNotFoundException(
            "Published course definition not found for this course.",
        );
    }

    /** @return array{CoursePublication, ?StoredEvent} */
    private function load(EntityId $course, ?EntityId $operation = null): array
    {
        $stream = $this->stream($course);
        $root = CoursePublication::root($stream->aggregateId);
        $accepted = null;
        $cursor = null;
        do {
            $page = $this->history->page($stream, $cursor, 50);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($operation !== null && $stored->event->payload()["version_id"] === $operation->value) {
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

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }

    /** @param array<mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize = function (mixed $v) use (&$normalize): mixed {
            if (!is_array($v)) {
                return $v;
            }
            ksort($v);
            return array_map($normalize, $v);
        };
        return hash("sha256", json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }

    private function stream(EntityId $course): StreamReference
    {
        return new StreamReference(new CoursePublicationId($course->value), "course_publication");
    }

    private function definition(EntityId $course): CourseDefinition
    {
        $row = $this->catalog->get("courses", $course);
        $ids = array_column($this->relationships->prerequisites($course), "id");
        sort($ids, SORT_STRING);
        $this->subjects->lockForCourse($course);
        $this->classifications->lockForCourse($course);
        return new CourseDefinition(
            (string) $row["code"],
            (string) $row["title"],
            (int) $row["credits_milli"],
            $ids,
            CourseText::fromArray($row),
            $this->subjects->course($course),
            $this->classifications->course($course),
        );
    }
}
