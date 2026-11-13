<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\ValueObject\AssistanceFailure;
use Domain\DegreeAudit\ValueObject\CourseGroupAuthoringFacts;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Event\RequirementsWerePublished;
use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\DegreeAudit\RequirementsPublication;
use Domain\DegreeAudit\ValueObject\AuditRequirements;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV2;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class RequirementsService
{
    public function __construct(
        private RequirementsRepository $templates,
        private CoursePublicationService $courses,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private CourseGroupService $groups,
        private RequirementProgramContext $programs,
        private PublishedRequirementsReader $publishedPrograms,
    ) {
    }

    /** Saved degree and selected publications, with bounded replay and no student reads.
     * @return array{draft:array<string,mixed>,revision:string,saved_revision:int}
     */
    public function assistanceSnapshot(EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $actor): array {
            $row = $this->lock($id);
            $this->authorize($actor);
            $definition = AuditRequirementsV3::draft($row["definition"]);
            if (!($definition instanceof AuditRequirementsV3)) {
                throw new InvalidArgumentException(
                    "Convert embedded groups before requesting degree assistance.",
                );
            }
            $context = $this->templates->context(
                new EntityId($row["program_id"]),
                new EntityId($row["academic_year_id"]),
            );
            $keys = [
                "name",
                "length_months",
                "min_credits_milli",
                "min_resident_milli",
                "min_cumulative_gpa_milli",
                "min_overall_gpa_milli",
                "policies",
            ];
            $draft = array_intersect_key($definition->definition, array_flip($keys));
            $draft["context"] = [
                "program_code" => $context["program_code"],
                "program_title" => $context["program_title"],
                "academic_year" => $context["academic_year_code"],
            ];
            $references = array_values(
                array_filter(
                    $definition->definition["groups"],
                    static fn(array $group): bool => $group["version_id"] !== null,
                ),
            );
            try {
                $selected = $this->publishedPrograms->groupVersions(
                    $references,
                    new HistoryReadBudget(maximumPages: 200, maximumEvents: 2000),
                );
            } catch (HistoryReadLimitException) {
                throw new AssistanceUnavailableException(AssistanceFailure::VerificationLimit);
            }
            $positions = [];
            foreach ($selected as $group) {
                foreach ($group["definition"]["courses"] as $course) {
                    if (!isset($positions[$course["course_id"]])) {
                        $positions[$course["course_id"]] = count($positions) + 1;
                    }
                }
            }
            $draft["groups"] = [];
            foreach ($definition->definition["groups"] as $index => $reference) {
                $group = [
                    "position" => $index + 1,
                    "published_version_selected" => $reference["version_id"] !== null,
                ];
                if ($reference["version_id"] !== null) {
                    $published = $selected[$reference["group_id"]];
                    $group["requirements"] = new CourseGroupAuthoringFacts(
                        CourseGroupDefinitionV2::draft($published["definition"]),
                        $published["course_details"],
                        $positions,
                    )->describe();
                }
                $draft["groups"][] = $group;
            }
            if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 65536) {
                throw new AssistanceUnavailableException(AssistanceFailure::VerificationLimit);
            }
            $this->authorize($actor);
            return [
                "draft" => $draft,
                "saved_revision" => $row["revision"],
                "revision" => hash("sha256", json_encode([$row, $context, $draft], JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function page(EntityId $program, ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $rows = $this->templates->page($program, $after);
        $items = array_map(static function ($r) {
            unset($r["definition"]);
            return $r;
        }, array_slice($rows, 0, 50));
        return ["items" => $items, "next_after" => count($rows) > 50 ? $rows[49]["id"] : null];
    }
    /** Open/create one working draft for a program and an existing academic year.
     * @return array<string, mixed>
     */
    public function open(EntityId $program, EntityId $year): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($program, $year, $actor): array {
            $this->templates->lockContext($program, $year);
            $context = $this->templates->context($program, $year);
            $row = $this->templates->find($program, $year);
            if ($row === null) {
                $id = EntityId::generate();
                $this->templates->create(
                    $id,
                    $program,
                    $year,
                    AuditRequirementsV3::blank($context["program_title"])->definition,
                );
                $this->audit->record($actor, "degree_audit.draft_created", $id);
                $row = $this->templates->get($id);
            }
            return $this->view(new EntityId($row["id"]));
        });
    }
    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $row = $this->templates->get($id);
        AuditRequirementsV3::draft($row["definition"]);
        return $row + [
            "grade_scale" => $this->groups->gradingScale(new EntityId($row["program_id"])),
            "context" => $this->templates->context(
                new EntityId($row["program_id"]),
                new EntityId($row["academic_year_id"]),
            ),
            "course_details" => $this->courseDetails(AuditRequirementsV3::draft($row["definition"])),
            "group_details" => $this->groupDetails(AuditRequirementsV3::draft($row["definition"])),
        ];
    }
    /** @return array<string, mixed> */
    public function save(EntityId $id, int $revision, AuditRequirementsV3 $definition): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $revision, $definition, $actor): array {
            $row = $this->lock($id);
            if (
                in_array($row["revision"], [$revision, $revision + 1], true) &&
                AuditRequirementsV3::draft($row["definition"])->hash() === $definition->hash()
            ) {
                return $this->view($id);
            }
            if ($row["revision"] !== $revision) {
                throw new ConcurrentModificationException("Reload requirements before saving.");
            }
            $this->templates->save($id, $revision, $definition->definition);
            $this->audit->record($actor, "degree_audit.draft_saved", $id);
            return $this->view($id);
        });
    }
    /** Clone a specific published definition into a fresh, empty target draft, never into student records.
     * @return array<string, mixed>
     */
    public function cloneTo(EntityId $source, EntityId $version, EntityId $program, EntityId $year): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $selected = $this->published($source, $version);
        return $this->transactions->run(function () use ($selected, $program, $year, $actor): array {
            $this->templates->lockContext($program, $year);
            $this->templates->context($program, $year);
            if ($this->templates->find($program, $year) !== null) {
                throw new ConcurrentModificationException(
                    "The target year already has a draft. Cloning never overwrites it.",
                );
            }
            $definition = $selected["definition"];
            foreach ($definition["groups"] as &$g) {
                if (array_key_exists("group_id", $g)) {
                    continue;
                }
                $g["id"] = EntityId::generate()->value;
                foreach ($g["rules"] as &$r) {
                    $r["id"] = EntityId::generate()->value;
                }
                unset($r);
            }
            unset($g);
            $definition = AuditRequirementsV3::draft($definition);
            $id = EntityId::generate();
            $this->templates->create($id, $program, $year, $definition->definition);
            $this->audit->record($actor, "degree_audit.draft_cloned", $id);
            return $this->view($id);
        });
    }
    /** @return array<string, mixed> */
    public function review(EntityId $id, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a reason of at most 1000 characters.");
        }
        return $this->transactions->run(function () use ($id, $actor, $reason): array {
            $row = $this->lock($id);
            $this->authorize($actor);
            $definition = AuditRequirementsV3::draft($row["definition"]);
            if (!($definition instanceof AuditRequirementsV3)) {
                throw new InvalidArgumentException(
                    "Compose this degree from reusable course groups before publishing a new version.",
                );
            }
            $definition->assertNewPublication();
            $this->assertScale($row["program_id"], $definition);
            $details = $this->courseDetails($definition);
            [$state] = $this->load($id);
            $state->assertScope($row["program_id"], $row["academic_year_id"]);
            if (
                $state->playhead() !== $row["publication_position"] ||
                $state->versionId() !== $row["published_version_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $op = EntityId::generate();
            new EventContext($actor, $this->clock->now(), $op, reason: $reason);
            $programPublication = $this->programs->resolve(
                new EntityId($row["program_id"]),
                new EntityId($row["academic_year_id"]),
            );
            $labels = $this->publicationContext($row, $programPublication);
            if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
                throw new AccessDeniedException("The publication reviewer changed.");
            }
            $data = [
                "kind" => "degree-audit-publication-review",
                "program_publication" => $programPublication,
                "grade_scale" => $this->groups->gradingScale(new EntityId($row["program_id"])),
                "template_id" => $id->value,
                "actor_id" => $actor->value,
                "operation_id" => $op->value,
                "revision" => $row["revision"],
                "expected_version" => $state->playhead(),
                "previous_version_id" => $state->versionId(),
                "definition" => $definition->definition,
                "context" => $labels,
                "reason" => $reason,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            return $data + [
                "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                "course_details" => $details,
                "group_details" => $this->groupDetails($definition),
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
                        "degree-audit-publication-review",
                        "degree-audit-publication-review.v1",
                        "degree-audit-publication-review.v2",
                        "degree-audit-publication-review.v3",
                        "degree-audit-publication-review.v4",
                    ],
                    true,
                ) ||
                ($data["template_id"] ?? null) !== $id->value ||
                !is_string($data["actor_id"] ?? null) ||
                !is_string($data["operation_id"] ?? null) ||
                !is_int($data["expected_version"] ?? null) ||
                $data["expected_version"] < 0 ||
                !is_string($data["reason"] ?? null) ||
                !is_array($data["definition"] ?? null) ||
                !is_array($data["context"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            new EntityId($data["operation_id"]);
            if ($data["kind"] === "degree-audit-publication-review") {
                if (
                    !is_int($data["expires_at"] ?? null) ||
                    array_key_exists("effective_at", $data) ||
                    !array_key_exists("program_publication", $data) ||
                    !is_int($data["revision"] ?? null) ||
                    !array_key_exists("previous_version_id", $data) ||
                    !is_array($data["grade_scale"] ?? null)
                ) {
                    throw new InvalidArgumentException();
                }
                new AuditRequirementsV3($data["definition"]);
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review these requirements again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This requirement review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($id, $data, $actor): array {
            $row = $this->lock($id);
            $op = new EntityId($data["operation_id"]);
            if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
                throw new AccessDeniedException("The publication reviewer changed.");
            }
            $c = $data["context"];
            $metadata = ["__aggregate_type" => "degree_audit_requirements"];
            if (($data["program_publication"] ?? null) !== null) {
                $metadata["curriculum_version_id"] = $data["program_publication"]["version_id"];
            }
            if ($data["kind"] === "degree-audit-publication-review.v2") {
                $metadata["schema_version"] = 2;
            }
            if ($data["kind"] === "degree-audit-publication-review.v3") {
                $metadata["schema_version"] = 3;
            }
            if (
                in_array(
                    $data["kind"],
                    ["degree-audit-publication-review", "degree-audit-publication-review.v4"],
                    true,
                )
            ) {
                $metadata["schema_version"] = 4;
            }
            $event = RequirementsWerePublished::occur(
                new RequirementsPublicationId($id->value),
                [
                    "program_id" => $c["program_id"],
                    "academic_year_id" => $c["academic_year_id"],
                    "version_id" => $op->value,
                    "previous_version_id" => $data["previous_version_id"],
                    "labels" => array_diff_key($c, array_flip(["program_id", "academic_year_id"])),
                    "definition" => $data["definition"],
                ] +
                    (in_array(
                        $data["kind"],
                        ["degree-audit-publication-review", "degree-audit-publication-review.v4"],
                        true,
                    )
                        ? ["grade_scale" => $data["grade_scale"]]
                        : []),
                $metadata,
            )->withPlayhead($data["expected_version"] + 1);
            [$state, $accepted] = $this->load($id, $op);
            $this->authorize($actor);
            $state->assertScope($row["program_id"], $row["academic_year_id"]);
            if ($accepted !== null) {
                if (
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $data["reason"] ||
                    $accepted->event->playhead() !== $event->playhead() ||
                    $accepted->event->metaParam("schema_version", 1) !==
                        $event->metaParam("schema_version", 1) ||
                    $accepted->event->metaParam("curriculum_version_id") !==
                        $event->metaParam("curriculum_version_id") ||
                    $this->fingerprint($accepted->event->payload()) !==
                        $this->fingerprint($event->payload()) ||
                    (array_key_exists("effective_at", $data) &&
                        $data["effective_at"] !== $accepted->context->describe()["effective_at"])
                ) {
                    throw new ConcurrentModificationException("This publication operation was already used.");
                }
                return [
                    "operation_id" => $op->value,
                    "first_version" => $accepted->event->playhead(),
                    "last_version" => $accepted->event->playhead(),
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            if ($data["kind"] !== "degree-audit-publication-review") {
                throw new ConcurrentModificationException("Prepare a fresh degree publication review.");
            }
            if ($data["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "This publication review expired. Prepare a fresh review.",
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
                $state->versionId() !== $data["previous_version_id"]
            ) {
                throw new ConcurrentModificationException(
                    "Requirements were published after this review. Review again.",
                );
            }
            $programPublication = $this->programs->resolve(
                new EntityId($row["program_id"]),
                new EntityId($row["academic_year_id"]),
            );
            if (
                !array_key_exists("program_publication", $data) ||
                $programPublication !== $data["program_publication"]
            ) {
                throw new ConcurrentModificationException(
                    "The applicable program publication changed. Review these requirements again.",
                );
            }
            $currentContext = $this->publicationContext($row, $programPublication);
            if (
                $row["revision"] !== $data["revision"] ||
                AuditRequirementsV3::draft($row["definition"])->hash() !==
                    AuditRequirementsV3::draft($data["definition"])->hash() ||
                $currentContext !== $c
            ) {
                throw new ConcurrentModificationException(
                    "Requirements or program details changed. Review again.",
                );
            }
            if ($this->groups->gradingScale(new EntityId($row["program_id"])) !== $data["grade_scale"]) {
                throw new ConcurrentModificationException(
                    "The grading scale changed. Review this degree publication again.",
                );
            }
            new AuditRequirementsV3($data["definition"])->assertNewPublication();
            $this->assertScale($row["program_id"], new AuditRequirementsV3($data["definition"]));
            $this->courseDetails(AuditRequirementsV3::draft($data["definition"]));
            $this->groupDetails(AuditRequirementsV3::draft($data["definition"]));
            $this->authorize($actor);
            if ($data["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "This publication review expired. Prepare a fresh review.",
                );
            }
            $receipt = $this->history->append(
                $this->stream($id),
                $op,
                $state->playhead(),
                new EventContext($actor, $this->clock->now(), $op, reason: $data["reason"]),
                [$event],
            );
            $this->templates->published($id, $op, $receipt["last_version"]);
            $this->audit->record($actor, "degree_audit.requirements_published", $id);
            return $receipt;
        });
    }
    /** @return array<string, mixed> */
    public function versions(EntityId $id, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->templates->get($id);
        $page = $this->history->page($this->stream($id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "schema_version" => $stored->event->metaParam("schema_version", 1),
                "program_version_id" => $stored->event->metaParam("curriculum_version_id"),
                "version_id" => $p["version_id"],
                "version" => $stored->event->playhead(),
                "definition" => $p["definition"],
                "grade_scale" => $p["grade_scale"] ?? null,
                "context" => $p["labels"] + [
                    "program_id" => $p["program_id"],
                    "academic_year_id" => $p["academic_year_id"],
                ],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "effective_at" => $stored->context->describe()["effective_at"],
            ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /** Curated published definition and its retained course labels; no event envelope.
     * @return array<string, mixed>
     */
    public function version(EntityId $id, EntityId $version): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $selected = $this->published($id, $version);
        return $selected + [
            "program_publication" =>
                ($selected["program_version_id"] ?? null) === null
                    ? null
                    : $this->publishedPrograms->programDefinition(
                        new EntityId($selected["context"]["program_id"]),
                        new EntityId($selected["context"]["academic_year_id"]),
                        new EntityId($selected["program_version_id"]),
                        $selected["context"],
                    ),
            "course_details" => $this->courseDetails(AuditRequirementsV3::draft($selected["definition"])),
            "group_details" => $this->groupDetails(AuditRequirementsV3::draft($selected["definition"])),
        ];
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed>|null $publication
     * @return array<string,mixed>
     */
    private function publicationContext(array $row, ?array $publication): array
    {
        $context = $this->templates->context(
            new EntityId($row["program_id"]),
            new EntityId($row["academic_year_id"]),
        );
        if ($publication !== null) {
            $context["program_code"] = $publication["definition"]["code"];
            $context["program_title"] = $publication["definition"]["title"];
        }
        return $context;
    }

    private function assertScale(string $program, AuditRequirementsV3 $definition): void
    {
        $scale = $this->groups->gradingScale(new EntityId($program));
        foreach (["min_cumulative_gpa_milli", "min_overall_gpa_milli"] as $key) {
            if (
                $definition->definition[$key] !== null &&
                $definition->definition[$key] > $scale["maximum_milli"]
            ) {
                throw new InvalidArgumentException(
                    "A degree GPA minimum exceeds the resolved grading scale.",
                );
            }
        }
        foreach ($this->groupDetails($definition) as $group) {
            $minimum = $group["definition"]["min_course_grade_points_milli"] ?? null;
            if ($minimum !== null && $minimum > $scale["maximum_milli"]) {
                throw new InvalidArgumentException(
                    "A course-group minimum exceeds this program’s resolved grading scale. " .
                        "Choose an appropriate group publication.",
                );
            }
        }
    }
    /** @return array<string, mixed> */
    private function published(EntityId $id, EntityId $version): array
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
        return $found ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Published requirements not found.");
    }
    /** @return array<string, mixed> */
    private function lock(EntityId $id): array
    {
        $r = $this->templates->get($id);
        $this->templates->lockContext(new EntityId($r["program_id"]), new EntityId($r["academic_year_id"]));
        $this->templates->lock($id);
        return $this->templates->get($id);
    }
    /** Convert working embedded groups into independent library drafts, without publishing historical claims.
     * @return array<string, mixed>
     */
    public function reuseLegacy(EntityId $id, int $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $revision, $actor): array {
            $row = $this->lock($id);
            $old = AuditRequirementsV3::draft($row["definition"]);
            if ($old instanceof AuditRequirementsV3) {
                return $this->view($id);
            }
            if ($row["revision"] !== $revision) {
                throw new ConcurrentModificationException("The draft changed. Review its groups again.");
            }
            $definition = $row["definition"];
            $references = [];
            foreach ($definition["groups"] as $group) {
                $members = [];
                foreach ($group["rules"] as &$rule) {
                    $rule["id"] = EntityId::generate()->value;
                    array_push($members, ...$rule["course_ids"]);
                }
                unset($rule);
                $courses = array_values(
                    array_filter(
                        $definition["courses"],
                        static fn($c) => in_array($c["course_id"], $members, true),
                    ),
                );
                $groupId = EntityId::generate();
                $this->groups->create(
                    $groupId,
                    new CourseGroupDefinition([
                        "name" => $group["name"],
                        "min_credits_milli" => $group["min_credits_milli"],
                        "min_gpa_milli" => $group["min_gpa_milli"],
                        "courses" => $courses,
                        "rules" => $group["rules"],
                    ]),
                );
                $references[] = ["group_id" => $groupId->value, "version_id" => null];
            }
            unset($definition["courses"]);
            $definition["groups"] = $references;
            $definition["policies"] ??= AuditRequirementsV2::blank($definition["name"])->definition[
                "policies"
            ];
            $definition["policies"]["group_reuse"] = CourseGroupReuse::OneGroup->value;
            $draft = new AuditRequirementsV3($definition);
            $this->templates->save($id, $revision, $draft->definition);
            $this->audit->record($actor, "degree_audit.groups_moved_to_library", $id);
            return $this->view($id);
        });
    }
    /** @return array<string, array<string, mixed>> */
    private function groupDetails(
        AuditRequirements|AuditRequirementsV2|AuditRequirementsV3 $definition,
    ): array {
        if (!($definition instanceof AuditRequirementsV3)) {
            return [];
        }
        $details = [];
        foreach ($definition->definition["groups"] as $g) {
            $id = new EntityId($g["group_id"]);
            $details[$id->value] =
            $g["version_id"] === null
            ? $this->groups->view($id)
            : $this->groups->version($id, new EntityId($g["version_id"]));
        }
        return $details;
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new RequirementsPublicationId($id->value), "degree_audit_requirements");
    }
    /** @return array{RequirementsPublication, ?StoredEvent} */
    private function load(EntityId $id, ?EntityId $operation = null): array
    {
        $s = $this->stream($id);
        $state = RequirementsPublication::root($s->aggregateId);
        $accepted = null;
        $cursor = null;
        do {
            $page = $this->history->page($s, $cursor, 20);
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
            throw new AccessDeniedException("The publication reviewer changed.");
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
    /** @return array<string, array<string, mixed>> */
    private function courseDetails(
        AuditRequirements|AuditRequirementsV2|AuditRequirementsV3 $definition,
    ): array {
        $rows = [];
        if ($definition instanceof AuditRequirementsV3) {
            return [];
        }
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
