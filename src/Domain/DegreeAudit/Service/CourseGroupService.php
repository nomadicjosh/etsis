<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\CourseGroupPublication;
use Domain\DegreeAudit\Event\CourseGroupWasPublished;
use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\DegreeAudit\ValueObject\CourseGroupPlan;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\EventSourcing\StoredEvent;
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
        private PublishedCourseReader $courses,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private GradingScaleService $gradeScale,
        private \Domain\Rules\Service\CourseRuleService $rules,
    ) {
    }
    /** @return array<string, mixed> */
    public function page(string $search = "", ?EntityId $after = null, bool $publishedOnly = false): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($search) > 80 || !mb_check_encoding($search, "UTF-8")) {
            throw new InvalidArgumentException("Use a bounded group search.");
        }
        $rows = $this->groups->page(search: trim($search), publishedOnly: $publishedOnly, after: $after);
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
            $this->assertRules($definition);
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
        $definition = CourseGroupDefinitionV2::draft($row["definition"]);
        $details = $this->courseDetails($definition);
        return $row + [
            "course_details" => $details,
            "planning_summary" => CourseGroupPlan::forDefinition($definition, $details)->describe(),
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
            $this->assertRules($definition);
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
            $this->authorize($actor);
            $row = $this->groups->get($id);
            $d = CourseGroupDefinitionV2::draft($row["definition"]);
            $d->assertPublishable();
            $this->assertRules($d);
            $this->assertCurrentScale($d);
            $details = $this->courseDetails($d);
            [$state] = $this->load($id);
            if (
                $state->playhead() !== $row["publication_position"] ||
                $state->versionId() !== $row["published_version_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $op = EntityId::generate();
            new EventContext($actor, $this->clock->now(), $op, reason: $reason);
            $this->authorize($actor);
            $data = [
                "kind" => "course-group-publication-review",
                "schema_version" => $d instanceof CourseGroupDefinitionV2 ? 2 : 1,
                "group_id" => $id->value,
                "actor_id" => $actor->value,
                "operation_id" => $op->value,
                "revision" => $row["revision"],
                "expected_version" => $state->playhead(),
                "previous_version_id" => $state->versionId(),
                "definition" => $d->definition,
                "reason" => $reason,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            return $data + [
                "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                "course_details" => $details,
                "planning_summary" => CourseGroupPlan::forDefinition($d, $details)->describe(),
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
                    [
                        "course-group-publication-review",
                        "course-group-publication-review.v1",
                        "course-group-publication-review.v2",
                    ],
                    true,
                ) ||
                ($data["group_id"] ?? null) !== $id->value ||
                !is_string($data["actor_id"] ?? null) ||
                !is_string($data["operation_id"] ?? null) ||
                !is_int($data["expected_version"] ?? null) ||
                $data["expected_version"] < 0 ||
                !is_int($data["revision"] ?? null) ||
                !is_string($data["reason"] ?? null) ||
                !is_array($data["definition"] ?? null) ||
                !array_key_exists("previous_version_id", $data)
            ) {
                throw new InvalidArgumentException();
            }
            new EntityId($data["operation_id"]);
            if ($data["previous_version_id"] !== null) {
                new EntityId($data["previous_version_id"]);
            }
            $schema = match ($data["kind"]) {
                "course-group-publication-review.v1" => 1,
                "course-group-publication-review.v2" => 2,
                default => $data["schema_version"] ?? null,
            };
            $definition = CourseGroupDefinitionV2::draft($data["definition"]);
            if ($schema !== ($definition instanceof CourseGroupDefinitionV2 ? 2 : 1)) {
                throw new InvalidArgumentException();
            }
            if (
                $data["kind"] === "course-group-publication-review" &&
                (!is_int($data["expires_at"] ?? null) || array_key_exists("effective_at", $data))
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this course group again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This group review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($id, $data, $actor, $schema): array {
            $this->groups->lock($id);
            $this->authorize($actor);
            $row = $this->groups->get($id);
            $op = new EntityId($data["operation_id"]);
            $metadata = ["__aggregate_type" => "course_group_publication", "schema_version" => $schema];
            $event = CourseGroupWasPublished::occur(
                $this->stream($id)->aggregateId,
                [
                    "version_id" => $op->value,
                    "previous_version_id" => $data["previous_version_id"],
                    "definition" => $data["definition"],
                ],
                $metadata,
            )->withPlayhead($data["expected_version"] + 1);
            [$state, $accepted] = $this->load($id, $op);
            $this->authorize($actor);
            if ($accepted !== null) {
                if (
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $data["reason"] ||
                    $accepted->event->playhead() !== $event->playhead() ||
                    $accepted->event->metaParam("schema_version", 1) !== $schema ||
                    $this->fingerprint($accepted->event->payload()) !==
                        $this->fingerprint($event->payload()) ||
                    (array_key_exists("effective_at", $data) &&
                        $data["effective_at"] !== $accepted->context->describe()["effective_at"])
                ) {
                    throw new ConcurrentModificationException(
                        "This group publication operation was already used.",
                    );
                }
                return [
                    "operation_id" => $op->value,
                    "first_version" => $accepted->event->playhead(),
                    "last_version" => $accepted->event->playhead(),
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            if ($data["kind"] !== "course-group-publication-review") {
                throw new ConcurrentModificationException("Prepare a fresh course group publication review.");
            }
            if ($data["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "This group publication review expired. Prepare a fresh review.",
                );
            }
            if (
                $state->playhead() !== $row["publication_position"] ||
                $state->versionId() !== $row["published_version_id"]
            ) {
                throw new CorruptHistoryException();
            }
            if (
                $state->playhead() !== $data["expected_version"] ||
                $state->versionId() !== $data["previous_version_id"] ||
                $row["revision"] !== $data["revision"] ||
                CourseGroupDefinitionV2::draft($row["definition"])->hash() !==
                    CourseGroupDefinitionV2::draft($data["definition"])->hash()
            ) {
                throw new ConcurrentModificationException("The group changed. Review publication again.");
            }
            $definition = CourseGroupDefinitionV2::draft($data["definition"]);
            $definition->assertPublishable();
            $this->assertRules($definition);
            $this->assertCurrentScale($definition);
            $this->courseDetails($definition);
            $this->authorize($actor);
            if ($data["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "This group publication review expired. Prepare a fresh review.",
                );
            }
            $receipt = $this->history->append(
                $this->stream($id),
                $op,
                $state->playhead(),
                new EventContext($actor, $this->clock->now(), $op, reason: $data["reason"]),
                [$event],
            );
            $this->groups->published($id, $op, $receipt["last_version"], $data["definition"]["name"]);
            $this->audit->record($actor, "course_group.published", $id);
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
        $definition = CourseGroupDefinitionV2::draft($found["definition"]);
        $details = $this->courseDetails($definition);
        return $found + [
            "course_details" => $details,
            "planning_summary" => CourseGroupPlan::forDefinition($definition, $details)->describe(),
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
    /** @return array{CourseGroupPublication, ?StoredEvent} */
    private function load(EntityId $id, ?EntityId $operation = null): array
    {
        $stream = $this->stream($id);
        $state = CourseGroupPublication::root($stream->aggregateId);
        $accepted = null;
        $cursor = null;
        do {
            $page = $this->history->page($stream, $cursor, 20);
            foreach ($page->events as $stored) {
                $state->acceptHistorical($stored->event);
                if ($operation !== null && $stored->event->payload()["version_id"] === $operation->value) {
                    if ($accepted !== null || $stored->context->correlation?->value !== $operation->value) {
                        throw new CorruptHistoryException();
                    }
                    $accepted = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return [$state, $accepted];
    }
    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The group publication reviewer changed.");
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
    private function assertRules(CourseGroupDefinition|CourseGroupDefinitionV2 $definition): void
    {
        $seen = [];
        foreach ($definition->definition['rules'] as $rule) {
            if (!isset($rule['eligibility'])) {
                continue;
            }
            $snapshot = new \Domain\Rules\SavedCourseRule($rule['eligibility']);
            $id = $snapshot->snapshot['id'];
            $expected = $seen[$id] ??= $this->rules->get(new EntityId($id));
            if ($this->fingerprint($expected) !== $this->fingerprint($snapshot->snapshot)) {
                throw new InvalidArgumentException('The selected course rule differs from its saved revision.');
            }
        }
    }

    /** @return array<string, array<string,mixed>> */
    private function courseDetails(CourseGroupDefinition|CourseGroupDefinitionV2 $definition): array
    {
        $rows = [];
        $budget = new HistoryReadBudget();
        foreach ($definition->definition['courses'] as $c) {
            $versions = $this->courses->selectedVersions(
                new EntityId($c['course_id']),
                array_map(
                    static fn(string $v): EntityId => new EntityId($v),
                    array_values([$c['version_id'], ...($c['accepted_version_ids'] ?? [])])
                ),
                $budget,
            );
            $describe = static function (string $version) use ($versions, $c): array {
                $selected = $versions[$version];
                $d = $selected['publication']->definition;
                return [
                    'course_id' => $c['course_id'], 'version_id' => $version, 'version' => $selected['version'],
                    'code' => $d->code, 'title' => $d->text->transcriptTitle ?? $d->title,
                    'credits_milli' => $d->creditsMilli,
                ];
            };
            $rows[$c['course_id']] = $describe($c['version_id']);
            if (($c['accepted_version_ids'] ?? []) !== []) {
                $rows[$c['course_id']]['accepted_versions'] = array_map($describe, $c['accepted_version_ids']);
            }
        }
        return $rows;
    }
}
