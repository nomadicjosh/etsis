<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\CourseGroupPublication;
use Domain\DegreeAudit\Event\CourseGroupWasPublished;
use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class CourseGroupService
{
    public function __construct(
        private CourseGroupRepository $groups,
        private CoursePublicationService $courses,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private GradingScaleService $gradeScale,
    ) {
    }
    /** @return array<string, mixed> */
    public function page(string $search = "", ?EntityId $after = null, bool $publishedOnly = false): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($search) > 80 || !mb_check_encoding($search, "UTF-8")) {
            throw new InvalidArgumentException("Use a bounded group search.");
        }
        $rows = $this->groups->page(trim($search), $after, $publishedOnly);
        return [
            "items" => array_slice($rows, 0, 50),
            "grade_point_scale" => $this->gradePointScale(),
            "next_after" => count($rows) > 50 ? $rows[49]["id"] : null,
        ];
    }
    /** @return array<string, mixed> */
    public function create(EntityId $id, CourseGroupDefinition|CourseGroupDefinitionV2 $definition): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $definition, $actor): array {
            try {
                $old = $this->groups->get($id);
            } catch (RecordNotFoundException) {
                $old = null;
            }
            if ($old !== null) {
                if (!hash_equals($old["creation_hash"], $definition->hash())) {
                    throw new ConcurrentModificationException(
                        "This creation reference belongs to another group.",
                    );
                }
                return $this->view($id);
            }
            $this->assertCurrentScale($definition);
            $this->groups->create($id, $definition->definition);
            $this->audit->record($actor, "course_group.draft_created", $id);
            return $this->view($id);
        });
    }
    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $row = $this->groups->get($id);
        return $row + [
            "course_details" => $this->courseDetails(CourseGroupDefinitionV2::draft($row["definition"])),
            "grade_point_scale" => $this->gradePointScale(
                isset($row["definition"]["grade_scale_program_id"])
                    ? new EntityId($row["definition"]["grade_scale_program_id"])
                    : null,
            ),
        ];
    }
    /** @return array<string, mixed> */
    public function save(
        EntityId $id,
        int $revision,
        CourseGroupDefinition|CourseGroupDefinitionV2 $definition,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $revision, $definition, $actor): array {
            $this->groups->lock($id);
            $row = $this->groups->get($id);
            if (
                in_array($row["revision"], [$revision, $revision + 1], true) &&
                CourseGroupDefinitionV2::draft($row["definition"])->hash() === $definition->hash()
            ) {
                return $this->view($id);
            }
            if ($row["revision"] !== $revision) {
                throw new ConcurrentModificationException("The group changed. Reload before saving.");
            }
            $this->assertCurrentScale($definition);
            $this->groups->save($id, $revision, $definition->definition);
            $this->audit->record($actor, "course_group.draft_saved", $id);
            return $this->view($id);
        });
    }
    /** @return array<string, mixed> */
    public function review(EntityId $id, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a publication reason of at most 1000 characters.");
        }
        return $this->transactions->run(function () use ($id, $actor, $reason): array {
            $this->groups->lock($id);
            $row = $this->groups->get($id);
            $d = CourseGroupDefinitionV2::draft($row["definition"]);
            $d->assertPublishable();
            $this->assertCurrentScale($d);
            $details = $this->courseDetails($d);
            $state = CourseGroupPublication::root($this->stream($id)->aggregateId);
            $cursor = null;
            do {
                $page = $this->history->page($this->stream($id), $cursor, 20);
                foreach ($page->events as $stored) {
                    $state->acceptHistorical($stored->event);
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            if (
                $state->playhead() !== $row["publication_position"] ||
                $state->versionId() !== $row["published_version_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $op = EntityId::generate();
            $context = new EventContext($actor, $this->clock->now(), $op, reason: $reason);
            $data = [
                "kind" =>
                    $d instanceof CourseGroupDefinitionV2
                        ? "course-group-publication-review.v2"
                        : "course-group-publication-review.v1",
                "group_id" => $id->value,
                "actor_id" => $actor->value,
                "operation_id" => $op->value,
                "revision" => $row["revision"],
                "expected_version" => $state->playhead(),
                "previous_version_id" => $state->versionId(),
                "definition" => $d->definition,
                "reason" => $reason,
                "effective_at" => $context->describe()["effective_at"],
            ];
            return $data + [
                "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                "course_details" => $details,
            ];
        });
    }
    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function publish(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 131072) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 20, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                !in_array(
                    $data["kind"] ?? null,
                    ["course-group-publication-review.v1", "course-group-publication-review.v2"],
                    true,
                ) ||
                ($data["group_id"] ?? null) !== $id->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this course group again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This group review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($id, $data, $actor): array {
            $this->groups->lock($id);
            $row = $this->groups->get($id);
            $op = new EntityId($data["operation_id"]);
            $metadata = ["__aggregate_type" => "course_group_publication"];
            if ($data["kind"] === "course-group-publication-review.v2") {
                $metadata["schema_version"] = 2;
            }
            $event = CourseGroupWasPublished::occur(
                $this->stream($id)->aggregateId,
                [
                    "version_id" => $op->value,
                    "previous_version_id" => $data["previous_version_id"],
                    "definition" => $data["definition"],
                ],
                $metadata,
            )->withPlayhead($data["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream($id),
                $op,
                $data["expected_version"],
                new EventContext(
                    $actor,
                    new DateTimeImmutable($data["effective_at"]),
                    $op,
                    reason: $data["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if (
                    $row["revision"] !== $data["revision"] ||
                    CourseGroupDefinitionV2::draft($row["definition"])->hash() !==
                        CourseGroupDefinitionV2::draft($data["definition"])->hash()
                ) {
                    throw new ConcurrentModificationException("The group changed. Review publication again.");
                }
                $this->assertCurrentScale(CourseGroupDefinitionV2::draft($data["definition"]));
                $this->courseDetails(CourseGroupDefinitionV2::draft($data["definition"]));
                $this->groups->published($id, $op, $receipt["last_version"], $data["definition"]["name"]);
                $this->audit->record($actor, "course_group.published", $id);
            }
            return $receipt;
        });
    }
    /** @return array<string, mixed> */
    public function versions(EntityId $id, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->groups->get($id);
        $page = $this->history->page($this->stream($id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "schema_version" => $stored->event->metaParam("schema_version", 1),
                "group_id" => $id->value,
                "version_id" => $p["version_id"],
                "version" => $stored->event->playhead(),
                "definition" => $p["definition"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "effective_at" => $stored->context->describe()["effective_at"],
            ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /** @return array<string, mixed> */
    public function version(EntityId $id, EntityId $version): array
    {
        $found = null;
        $cursor = null;
        do {
            $page = $this->versions($id, $cursor);
            foreach ($page["items"] as $item) {
                if ($item["version_id"] === $version->value) {
                    $found = $item;
                }
            }
            $cursor = $page["next_cursor"];
        } while ($cursor !== null);
        if ($found === null) {
            throw new RecordNotFoundException("Published group version not found for this group.");
        }
        return $found + [
            "course_details" => $this->courseDetails(CourseGroupDefinitionV2::draft($found["definition"])),
        ];
    }
    /** Authorized authoring context, also used by the degree composition workflow.
     * @return array<string,mixed>
     */
    public function gradingScale(?EntityId $program = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        return $this->gradePointScale($program);
    }
    /** @return array<string,mixed> */
    private function gradePointScale(?EntityId $program = null): array
    {
        return $this->gradeScale->resolve($program);
    }
    private function assertCurrentScale(CourseGroupDefinition|CourseGroupDefinitionV2 $definition): void
    {
        if (
            $definition instanceof CourseGroupDefinitionV2 &&
            $definition->definition["max_course_grade_points_milli"] !==
                $this->gradePointScale(
                    $definition->definition["grade_scale_program_id"] === null
                        ? null
                        : new EntityId($definition->definition["grade_scale_program_id"]),
                )["maximum_milli"]
        ) {
            throw new ConcurrentModificationException(
                "Grade definitions changed the maximum grade points. " .
                    "Reload the group and review its minimum before publishing.",
            );
        }
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new CourseGroupPublicationId($id->value), "course_group_publication");
    }
    /** @return array<string, array<string, mixed>> */
    private function courseDetails(CourseGroupDefinition|CourseGroupDefinitionV2 $definition): array
    {
        $rows = [];
        foreach ($definition->definition["courses"] as $c) {
            $d = $this->courses->publishedDefinition(
                new EntityId($c["course_id"]),
                new EntityId($c["version_id"]),
            );
            $rows[$c["course_id"]] = [
                "course_id" => $c["course_id"],
                "version_id" => $c["version_id"],
                "code" => $d->definition->code,
                "title" => $d->definition->text->transcriptTitle ?? $d->definition->title,
                "credits_milli" => $d->definition->creditsMilli,
            ];
        }
        return $rows;
    }
}
