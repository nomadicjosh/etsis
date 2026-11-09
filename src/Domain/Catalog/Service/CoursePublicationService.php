<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\CoursePublication;
use Domain\Catalog\Event\CourseDefinitionWasPublished;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Catalog\ValueObject\CourseDefinitionV2;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\EventContext;
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
    ) {
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
            $definition = $this->definition($course)->describe();
            $stream = $this->stream($course);
            $publication = CoursePublication::root($stream->aggregateId);
            $cursor = null;
            do {
                $page = $this->history->page($stream, $cursor, 50);
                foreach ($page->events as $stored) {
                    $publication->acceptHistorical($stored->event);
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            $operation = EntityId::generate();
            $context = new EventContext($actor, $this->clock->now(), $operation, reason: $reason);
            $data = [
                "kind" => "course-publication-review.v2",
                "course_id" => $course->value,
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $publication->playhead(),
                "previous_version_id" => $publication->versionId(),
                "definition" => $definition,
                "effective_at" => $context->describe()["effective_at"],
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
            if (strlen($token) > 262144) {
                throw new InvalidArgumentException();
            }
            $review = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($review) ||
                !in_array(
                    $review["kind"] ?? null,
                    ["course-publication-review.v1", "course-publication-review.v2"],
                    true,
                ) ||
                ($review["course_id"] ?? null) !== $course->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this course again before publishing.");
        }
        if ($review["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This publication review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($course, $actor, $review): array {
            $this->catalog->lock("courses", $course);
            $operation = new EntityId($review["operation_id"]);
            $metadata = ["__aggregate_type" => "course_publication"];
            if ($review["kind"] === "course-publication-review.v2") {
                $metadata["schema_version"] = 2;
            }
            $event = CourseDefinitionWasPublished::occur(
                $this->stream($course)->aggregateId,
                [
                    "version_id" => $operation->value,
                    "previous_version_id" => $review["previous_version_id"],
                    "definition" => $review["definition"],
                ],
                $metadata,
            )->withPlayhead($review["expected_version"] + 1);
            $context = new EventContext(
                $actor,
                new DateTimeImmutable($review["effective_at"], new DateTimeZone("UTC")),
                $operation,
                reason: $review["reason"],
            );
            $receipt = $this->history->append(
                $this->stream($course),
                $operation,
                $review["expected_version"],
                $context,
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                // Validate the reviewed draft within the same transaction. Failure also rolls back the new event.
                if (
                    $review["kind"] !== "course-publication-review.v2" ||
                    $this->definition($course)->describe() !== $review["definition"]
                ) {
                    throw new ConcurrentModificationException(
                        "The course changed. Review it again before publishing.",
                    );
                }
                $this->textProjection->apply(
                    $course,
                    CourseText::fromArray($review["definition"]),
                    $operation,
                    $receipt["last_version"],
                );
                $this->audit->record($actor, "course.definition_published", $course);
            }
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
                "schema_version" => $stored->event->metaParam("schema_version", 1),
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

    private function stream(EntityId $course): StreamReference
    {
        return new StreamReference(new CoursePublicationId($course->value), "course_publication");
    }

    private function definition(EntityId $course): CourseDefinitionV2
    {
        $row = $this->catalog->get("courses", $course);
        $ids = array_column($this->relationships->prerequisites($course), "id");
        sort($ids, SORT_STRING);
        return new CourseDefinitionV2(
            new CourseDefinition(
                (string) $row["code"],
                (string) $row["title"],
                (int) $row["credits_milli"],
                $ids,
            ),
            CourseText::fromArray($row),
        );
    }
}
