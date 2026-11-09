<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Event\SectionCourseVersionWasBound;
use Domain\Registration\Repository\SectionCourseBindingRepository;
use Domain\Registration\ValueObject\SectionCourseBindingId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Throwable;

final readonly class SectionCourseBindingService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private CoursePublicationService $publications,
        private SectionCourseBindingRepository $bindings,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(EntityId $section, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $row = $this->catalog->get("sections", $section);
        $bound = $this->bindings->bound($section);
        return [
            "section" => $row,
            "bound" => $bound?->describe(),
            "has_student_records" => $this->bindings->hasStudentRecords($section),
            "versions" =>
                $bound === null
                    ? $this->publications->versions(new EntityId((string) $row["course_id"]), $cursor)
                    : ["items" => [], "next_cursor" => null],
        ];
    }

    /** @return array<string, mixed> */
    public function review(
        EntityId $section,
        EntityId $version,
        #[\SensitiveParameter] string $reason,
        ?EntityId $correlation = null,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a reason of at most 1000 characters.");
        }
        return $this->transactions->run(function () use (
            $section,
            $version,
            $reason,
            $actor,
            $correlation,
        ): array {
            $row = $this->lock($section);
            $this->assertCanBind($section);
            $published = $this->find(new EntityId((string) $row["course_id"]), $version);
            $operation = EntityId::generate();
            $context = new EventContext(
                $actor,
                $this->clock->now(),
                $correlation ?? $operation,
                reason: $reason,
            );
            $data = [
                "kind" => "section-course-binding-review.v2",
                "section_id" => $section->value,
                "actor_id" => $actor->value,
                "operation_id" => $operation->value,
                "correlation_id" => ($correlation ?? $operation)->value,
                "section_revision" => $this->revision($row),
                "selected" => $published->describe(),
                "reason" => $reason,
                "effective_at" => $context->describe()["effective_at"],
            ];
            $proof = $data;
            $proof["selected"] = array_intersect_key(
                $data["selected"],
                array_flip(["course_id", "version_id", "schema_version"]),
            );
            $proof["definition_hash"] = $this->definitionHash($published);
            return $data + [
                "section_code" => $row["code"],
                "review_token" => $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function commit(EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 65536) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                !in_array(
                    $data["kind"] ?? null,
                    ["section-course-binding-review.v1", "section-course-binding-review.v2"],
                    true,
                ) ||
                ($data["section_id"] ?? null) !== $section->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review the section course definition again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This section review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($section, $data, $actor): array {
            $row = $this->lock($section);
            $course = new EntityId((string) $row["course_id"]);
            $version = new EntityId($data["selected"]["version_id"]);
            $operation = new EntityId($data["operation_id"]);
            $event = SectionCourseVersionWasBound::occur(
                new SectionCourseBindingId($section->value),
                ["course_id" => $course->value, "course_version_id" => $version->value],
                ["__aggregate_type" => "section_course_binding"],
            );
            $receipt = $this->history->append(
                new StreamReference($event->aggregateId(), "section_course_binding"),
                $operation,
                0,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($data["effective_at"]),
                    new EntityId($data["correlation_id"] ?? $operation->value),
                    reason: $data["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                $this->assertCanBind($section);
                if (!hash_equals($this->revision($row), $data["section_revision"])) {
                    throw new ConcurrentModificationException(
                        "This section changed. Review its course definition again.",
                    );
                }
                $published = $this->find($course, $version);
                $matches =
                    $data["kind"] === "section-course-binding-review.v2"
                        ? hash_equals($this->definitionHash($published), $data["definition_hash"])
                        : $published->describe() === $data["selected"];
                if (!$matches) {
                    throw new ConcurrentModificationException(
                        "The selected publication could not be verified.",
                    );
                }
                $this->bindings->retain($section, $published);
                $this->audit->record($actor, "section.course_version_bound", $section);
            }
            return $receipt;
        });
    }

    private function assertCanBind(EntityId $section): void
    {
        if ($this->bindings->bound($section) !== null) {
            throw new DomainException(
                "This section already retains a course definition. " .
                    "Create another offering for a different version.",
            );
        }
        if ($this->bindings->hasStudentRecords($section)) {
            throw new DomainException(
                "This section has student records. " .
                    "Use a new section rather than assigning a definition retrospectively.",
            );
        }
    }

    /** @return array<string, scalar|null> */
    private function lock(EntityId $section): array
    {
        $this->catalog->lock("sections", $section);
        $row = $this->catalog->get("sections", $section);
        $this->catalog->lock("courses", new EntityId((string) $row["course_id"]));
        return $row;
    }

    private function find(EntityId $course, EntityId $version): PublishedCourseDefinition
    {
        return $this->publications->publishedDefinition($course, $version);
    }

    private function definitionHash(PublishedCourseDefinition $definition): string
    {
        return hash("sha256", json_encode($definition->describe(), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, scalar|null> $row */
    private function revision(array $row): string
    {
        ksort($row);
        return hash(
            "sha256",
            json_encode(
                array_map(static fn($v) => $v === null ? null : (string) $v, $row),
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
