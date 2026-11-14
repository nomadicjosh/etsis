<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted;
use Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked;
use Domain\DegreeAudit\Repository\RequirementAdjustmentRepository;
use Domain\DegreeAudit\RequirementAdjustments;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2 as Definition;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;
use JsonException;
use Qubus\Exception\Data\TypeException;
use Throwable;

final readonly class RequirementAdjustmentService
{
    public const PERMISSION = "sis:degree-audit:adjust";
    public function __construct(
        private RequirementAdjustmentRepository $projection,
        private StudentRequirementsService $requirements,
        private StudentRecordRepository $students,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private PublishedRequirementsReader $published,
        private \Domain\DegreeAudit\Repository\ReplacementCourseRepository $replacementCourses,
        private StudentCurriculumContext $curriculum,
        private SavedCourseRulePreview $rules,
    ) {
    }
    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $assignment, ?EntityId $specialization = null): array
    {
        return $this->read($student, $assignment, false, $specialization);
    }
    /** Selected publication and verified approvals from the same locked read; no completion calculation.
     * @return array<string,mixed>
     */
    public function preview(EntityId $student, EntityId $assignment, ?EntityId $specialization = null): array
    {
        return $this->read($student, $assignment, true, $specialization);
    }
    /** Internal allocation input; the caller holds the student lock and shared read budget.
     * @return array<string,mixed>
     */
    public function allocationContext(
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
        ?EntityId $specialization = null,
    ): array {
        $parent = $this->curriculum->resolve($student, $assignment, $budget, $specialization);
        $state = $this->state($assignment, $parent, $budget);
        $effective = $this->effective($parent, $state->active(), $budget);
        $this->requirements->authorizeRead($student, $assignment);
        $waived = [];
        foreach ($state->active() as $item) {
            if ($item["definition"]["kind"] === "waiver") {
                $waived[$item["definition"]["course_id"]] = true;
            }
        }
        $waived = array_keys($waived);
        sort($waived, SORT_STRING);
        return $parent + ["effective_minimums" => $effective, "pursuit_waived_course_ids" => $waived];
    }

    /**
     * Shared verified exception inputs for the assigned degree and all selected specializations.
     * Caller holds the student lock. No academic inventory or allocation is loaded here.
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function curriculumContext(EntityId $student, EntityId $assignment, HistoryReadBudget $budget): array
    {
        $all = $this->curriculum->all($student, $assignment, $budget);
        $parent = $all["degree"];
        $state = $this->state($assignment, $parent, $budget);
        $components = [];
        $publications = [];
        if ($parent["requirements"] !== null) {
            $components[] = [
                "kind" => "degree",
                "requirements" => $parent["requirements"],
                "effective" => $this->effective($parent, $state->active(), $budget, $publications),
            ];
        }
        foreach ($all["specializations"]["requirements"] as $id => $publication) {
            $context = StudentCurriculumContext::specialization(
                $student,
                $assignment,
                new EntityId($id),
                $all["specializations"],
            );
            $components[] = [
                "kind" => "specialization",
                "requirements" => $publication,
                "effective" => $this->effective($context, $state->active(), $budget, $publications),
            ];
        }
        $routes = [];
        $stale = false;
        foreach ($state->active() as $p) {
            if ($p["definition"]["kind"] !== "component_route") {
                continue;
            }
            $d = $p["definition"];
            $applicable = hash_equals($d["context_hash"], $all["context_hash"]);
            $stale = $stale || !$applicable;
            $routes[] = $p + ["applicable" => $applicable];
            if ($applicable) {
                foreach ($components as &$component) {
                    if ($component["requirements"]["version_id"] !== $d["target_version_id"]) {
                        $component["effective"]["excluded_course_ids"][] = $d["course_id"];
                    }
                }
                unset($component);
            }
        }
        return $all + ["components" => $components, "routes" => $routes, "routing_changed" => $stale];
    }

    /** Curated staff routing workspace, using the same exceptions and reviewed confirmation endpoint.
     * @return array<string,mixed>
     */
    public function routing(EntityId $student, EntityId $assignment): array
    {
        $actor = $this->access->requirePermission("sis:student:read");
        $this->requirements->authorizeRead($student, $assignment);
        return $this->transactions->run(function () use ($student, $assignment, $actor): array {
            $this->students->lock($student);
            $all = $this->curriculumContext($student, $assignment, new HistoryReadBudget());
            $parent = $all["degree"];
            $components = [];
            foreach ($all["components"] as $component) {
                $r = $component["requirements"];
                $components[] = [
                    "version_id" => $r["version_id"],
                    "name" => $r["definition"]["name"],
                    "kind" => $component["kind"],
                    "specialization" => $r["specialization"] ?? null,
                ];
            }
            $this->requirements->authorizeRead($student, $assignment);
            $budget = new HistoryReadBudget();
            $publications = [];
            $items = [];
            foreach ($all["routes"] as $p) {
                $items[] = $p + $this->labels($p, $publications, $budget);
            }
            if ($this->access->requirePermission("sis:student:read")->value !== $actor->value) {
                throw new AccessDeniedException("The routing reader changed.");
            }
            return [
                "routing_components" => $components,
                "items" => $items,
                "substitution_details" => $this->substitutionDetails($items, $budget, $publications),
                "routing_changed" => $all["routing_changed"],
                "selection" => $parent["selection"],
                "scope" => $parent["scope"],
                "courses" => [],
                "targets" => [],
                "effective_minimums" => DegreeProgressEvaluator::unadjusted(
                    $parent["requirements"] ?? [
                        "definition" => [],
                        "group_details" => [],
                    ],
                ),
            ];
        });
    }

    /**
     * @param array<string,mixed> $request
     * @throws JsonException
     */
    private function routeProposal(EntityId $student, EntityId $assignment, array $request): Definition
    {
        Definition::keys($request, ["kind", "course_id", "course_version_id", "target_version_id"]);
        foreach (["course_id", "course_version_id", "target_version_id"] as $key) {
            if (!is_string($request[$key])) {
                throw new InvalidArgumentException("Choose the course publication and receiving curriculum component.");
            }
            new EntityId($request[$key]);
        }
        $budget = new HistoryReadBudget();
        $all = $this->curriculum->all($student, $assignment, $budget);
        if (
            $all["specializations"]["cohort_changed"] ||
            $all["specializations"]["program"] === null ||
            count($all["specializations"]["requirements"]) !==
                count($all["specializations"]["selection"]["specialization_ids"] ?? []) ||
            $all["degree"]["requirements"]["program_version_id"] !==
                $all["specializations"]["scope"]["program_version_id"]
        ) {
            throw new InvalidArgumentException(
                "Review the cohort and assign all selected curriculum components first.",
            );
        }
        $publications = [$all["degree"]["requirements"], ...array_values($all["specializations"]["requirements"])];
        if (count($publications) < 2) {
            throw new InvalidArgumentException(
                "Assign specialization requirements before routing a course between components.",
            );
        }
        foreach ($publications as $r) {
            if ($r["version_id"] === $request["target_version_id"]) {
                $this->published->courseVersion(
                    new EntityId($request["course_id"]),
                    new EntityId($request["course_version_id"]),
                    $budget,
                );
                return new Definition(
                    $request + ["target_template_id" => $r["template_id"], "context_hash" => $all["context_hash"]],
                );
            }
        }
        throw new InvalidArgumentException("Choose an exact currently assigned curriculum component.");
    }

    /** @return array<string,mixed> */
    private function read(
        EntityId $student,
        EntityId $assignment,
        bool $includeRequirements,
        ?EntityId $specialization,
    ): array {
        $actor = $this->access->authenticatedUserId();
        $this->requirements->authorizeRead($student, $assignment);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $includeRequirements,
            $specialization,
            $actor,
        ): array {
            $this->students->lock($student);
            $parent = $this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization);
            $state = $this->state($assignment, $parent);
            $choices = $this->choices($parent);
            $budget = new HistoryReadBudget();
            $publications = [];
            $effective = $this->effective($parent, $state->active(), $budget, $publications);
            $details = $this->substitutionDetails($state->active(), $budget, $publications);
            $this->requirements->authorizeRead($student, $assignment);
            $items = [];
            foreach ($state->active() as $p) {
                if (
                    $p["definition"]["kind"] !== "component_route" &&
                    ($specialization === null ||
                        ($p["definition"]["component"]["specialization_id"] ?? null) === $specialization->value ||
                        $p["definition"]["kind"] === "waiver")
                ) {
                    $items[] = $p +
                        $this->labels($p, $publications, $budget) + ["applicable" => $this->applicable($parent, $p)];
                }
            }
            $this->requirements->authorizeRead($student, $assignment);
            if ($this->access->authenticatedUserId()->value !== $actor->value) {
                throw new AccessDeniedException("The exception reader changed.");
            }
            return [
                "items" => $items,
                "component" => $parent["requirements"]["specialization"] ?? null,
                "targets" => $choices["targets"],
                "courses" => $choices["courses"],
                "placement_courses" => $this->placementChoices($parent, $state->active(), $details, $effective),
                "effective_minimums" => $effective,
                "group_labels" => $this->groupLabels($parent),
                "grade_scale" => $parent["requirements"]["grade_scale"] ?? null,
                "selection" => $parent["selection"],
                "scope" => $parent["scope"],
                "cohort_changed" => $parent["cohort_changed"],
                "substitution_details" => $details,
            ] + ($includeRequirements ? ["requirements" => $parent["requirements"]] : []);
        });
    }
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed> */
    public function reviewGrant(
        EntityId $student,
        EntityId $assignment,
        array $request,
        #[\SensitiveParameter] string $reason,
        ?EntityId $specialization = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $request,
            $reason,
            $actor,
            $specialization,
        ): array {
            $this->students->lock($student);
            $parent = $this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization);
            $this->writable($parent);
            $state = $this->state($assignment, $parent);
            if (array_key_exists("component", $request)) {
                throw new InvalidArgumentException("Component references are supplied by the server.");
            }
            if (($request["kind"] ?? null) === "component_route") {
                if ($specialization !== null) {
                    throw new InvalidArgumentException("Review course routing from the combined curriculum.");
                }
                $definition = $this->routeProposal($student, $assignment, $request);
            } else {
                $definition = $this->proposal($parent, $request, $state->active());
                if ($specialization !== null) {
                    $definition = new Definition(
                        $definition->definition + [
                            "component" => [
                                "specialization_id" => $specialization->value,
                                "cohort_binding_revision" => $parent["scope"]["cohort_binding_revision"],
                            ],
                        ],
                    );
                }
            }
            $id = EntityId::generate();
            $p = [
                "adjustment_id" => $id->value,
                "supersedes_adjustment_id" => null,
                "student_id" => $student->value,
                "program_id" => $parent["scope"]["program_id"],
                "requirement_decision_id" => $parent["selection"]["decision_id"],
                "template_id" => $parent["selection"]["template_id"],
                "requirements_version_id" => $parent["selection"]["requirements_version_id"],
                "definition" => $definition->definition,
            ];
            $previous = $state->forKey(RequirementAdjustments::key($p));
            if (
                $previous !== null &&
                in_array($definition->definition["kind"], ["placement", "course_version", "component_route"], true) &&
                ($definition->definition["kind"] === "component_route" || $this->applicable($parent, $previous)) &&
                $this->same($previous["definition"], $definition->definition)
            ) {
                throw new InvalidArgumentException("This course already has that exception.");
            }
            $p["supersedes_adjustment_id"] = $previous["adjustment_id"] ?? null;
            $active = $state->active();
            if ($previous !== null) {
                unset($active[$previous["adjustment_id"]]);
            }
            $active[$id->value] = $p;
            if (count($active) > RequirementAdjustments::MAX_ACTIVE) {
                throw new InvalidArgumentException(
                    "Review and revoke obsolete approvals before adding more exceptions.",
                );
            }
            $effective = $this->effective($parent, $active);
            $this->noSubstitutionConflicts($effective);
            return $this->review($assignment, $actor, $state->playhead(), $parent, "grant", $p, $reason) + [
                "previous" => $previous,
                "effective_minimums" => $effective,
                "affected_groups" => $this->affected($parent, $definition),
                "substitution_details" => $this->substitutionDetails([$p]),
            ];
        });
    }
    /** @return array<string, mixed> */
    public function reviewRevoke(
        EntityId $student,
        EntityId $assignment,
        EntityId $adjustment,
        #[\SensitiveParameter] string $reason,
        ?EntityId $specialization = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $adjustment,
            $reason,
            $actor,
            $specialization,
        ): array {
            $this->students->lock($student);
            $parent = $this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization);
            $this->writable($parent, requireSelection: false);
            $state = $this->state($assignment, $parent);
            $active = $state->active();
            $existing =
                $active[$adjustment->value] ?? throw new RecordNotFoundException("Active student exception not found.");
            if (
                $specialization !== null &&
                $existing["definition"]["kind"] !== "waiver" &&
                ($existing["definition"]["component"]["specialization_id"] ?? null) !== $specialization->value
            ) {
                throw new InvalidArgumentException("Review this exception in its original curriculum component.");
            }
            unset($active[$adjustment->value]);
            $effective = $this->effective($parent, $active);
            $publications = [];
            $p = [
                "adjustment_id" => $adjustment->value,
                "decision_id" => EntityId::generate()->value,
                "student_id" => $student->value,
                "program_id" => $parent["scope"]["program_id"],
            ];
            return $this->review(
                $assignment,
                $actor,
                $state->playhead(),
                $parent,
                "revoke",
                $p,
                $reason,
                isset($existing["definition"]["component"])
                    ? "requirement-curriculum-review"
                    : match ($existing["definition"]["kind"]) {
                        "placement" => "requirement-placement-review",
                        "course_version" => "requirement-course-version-review",
                        "component_route" => "requirement-curriculum-review",
                        default => null,
                    },
            ) + [
                "previous" => $existing + $this->labels($existing, $publications, new HistoryReadBudget()),
                "effective_minimums" => $effective,
                "affected_groups" => [],
            ];
        });
    }
    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function commit(
        EntityId $student,
        EntityId $assignment,
        #[\SensitiveParameter] string $token,
        ?EntityId $specialization = null,
    ): array {
        $actor = $this->authorize();
        $this->requirements->view($student, $assignment);
        try {
            if (strlen($token) > 32768) {
                throw new InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                !in_array(
                    $d["kind"] ?? null,
                    [
                        "requirement-adjustment-review.v1",
                        "requirement-adjustment-review.v2",
                        "requirement-placement-review",
                        "requirement-course-version-review",
                        "requirement-curriculum-review",
                    ],
                    true,
                ) ||
                ($d["assignment_id"] ?? null) !== $assignment->value ||
                ($d["payload"]["student_id"] ?? null) !== $student->value ||
                !in_array($d["action"] ?? null, ["grant", "revoke"], true)
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this student exception again.");
        }
        if (($d["specialization_id"] ?? null) !== $specialization?->value) {
            throw new InvalidArgumentException("Confirm this exception from its reviewed curriculum component.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This exception review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $assignment, $actor, $d): array {
            $this->students->lock($student);
            $specialization = isset($d["specialization_id"]) ? new EntityId($d["specialization_id"]) : null;
            $parent = $this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization);
            $state = $this->state($assignment, $parent);
            $this->assertActor($actor);
            $p = $d["payload"];
            $op = new EntityId($d["action"] === "grant" ? $p["adjustment_id"] : $p["decision_id"]);
            $class =
                $d["action"] === "grant"
                    ? RequirementAdjustmentWasGranted::class
                    : RequirementAdjustmentWasRevoked::class;
            $metadata = ["__aggregate_type" => "student_requirement_adjustments"];
            if ($d["kind"] === "requirement-curriculum-review" && $d["action"] === "grant") {
                $metadata["schema_version"] = 2;
            }
            if ($d["action"] === "grant" && $p["definition"]["kind"] === "placement") {
                if (!in_array($d["kind"], ["requirement-placement-review", "requirement-curriculum-review"], true)) {
                    throw new InvalidArgumentException("Review this placement preference again.");
                }
                $metadata["schema_version"] = 2;
            }
            if ($d["action"] === "grant" && $p["definition"]["kind"] === "course_version") {
                if (
                    !in_array($d["kind"], ["requirement-course-version-review", "requirement-curriculum-review"], true)
                ) {
                    throw new InvalidArgumentException("Review this course publication approval again.");
                }
                $metadata["schema_version"] = 2;
            }
            if ($d["action"] === "grant" && $p["definition"]["kind"] === "substitution") {
                if (
                    !in_array($d["kind"], ["requirement-adjustment-review.v2", "requirement-curriculum-review"], true)
                ) {
                    throw new InvalidArgumentException("Review this substitution again.");
                }
                $metadata["schema_version"] = 2;
            }
            $event = $class
                ::occur($this->stream($assignment)->aggregateId, $p, $metadata)
                ->withPlayhead($d["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream($assignment),
                $op,
                $d["expected_version"],
                new EventContext($actor, new DateTimeImmutable($d["effective_at"]), $op, reason: $d["reason"]),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if (
                    in_array(
                        $d["kind"],
                        [
                            "requirement-placement-review",
                            "requirement-course-version-review",
                            "requirement-curriculum-review",
                        ],
                        true,
                    ) &&
                    (!is_int($d["expires_at"] ?? null) || $d["expires_at"] <= $this->clock->now()->getTimestamp())
                ) {
                    throw new ConcurrentModificationException("Review this exception again; its review expired.");
                }
                $this->writable($parent, requireSelection: $d["action"] === "grant");
                if (($parent["selection"]["decision_id"] ?? null) !== $d["selection_decision_id"]) {
                    throw new ConcurrentModificationException(
                        "The selected degree requirements changed. Review this exception again.",
                    );
                }
                if ($d["action"] === "grant" && $p["definition"]["kind"] === "component_route") {
                    $all = $this->curriculum->all($student, $assignment, new HistoryReadBudget());
                    if (!hash_equals($p["definition"]["context_hash"], $all["context_hash"])) {
                        throw new ConcurrentModificationException(
                            "The curriculum assignments changed. Review course routing again.",
                        );
                    }
                }
                $state->acceptHistorical($event);
                $effective = $this->effective($parent, $state->active());
                if ($d["action"] === "grant") {
                    $this->noSubstitutionConflicts($effective);
                }
                if ($d["action"] === "grant") {
                    $this->projection->grant($assignment, $p);
                } else {
                    $this->projection->revoke($assignment, new EntityId($p["adjustment_id"]));
                }
                $this->audit->record($actor, "degree_audit.exception_" . $d["action"], $assignment);
            }
            $this->assertActor($actor);
            return $receipt;
        });
    }
    /** @return array<string, mixed> */
    public function history(EntityId $student, EntityId $assignment, ?string $cursor = null): array
    {
        $parent = $this->requirements->view($student, $assignment);
        $page = $this->history->page($this->stream($assignment), $cursor, 10);
        $items = [];
        $publications = [];
        $budget = new HistoryReadBudget();
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            if ($p["student_id"] !== $student->value || $p["program_id"] !== $parent["scope"]["program_id"]) {
                throw new CorruptHistoryException();
            }
            $labels = [];
            if ($stored->event instanceof RequirementAdjustmentWasGranted) {
                $labels = $this->labels($p, $publications, $budget);
            }
            $items[] = $p +
            $labels + [
                    "action" => $stored->event instanceof RequirementAdjustmentWasGranted ? "grant" : "revoke",
                    "version" => $stored->event->playhead(),
                    "effective_at" => $stored->context->describe()["effective_at"],
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                ];
        }
        $this->requirements->authorizeRead($student, $assignment);
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /**
     * Minimal current catalog search, followed by explicit verified publication selection.
     * @return array<string,mixed> */
    public function replacementCourses(
        EntityId $student,
        EntityId $assignment,
        string $search = "",
        ?EntityId $after = null,
        ?EntityId $specialization = null,
    ): array {
        $this->authorize();
        $this->writable($this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization));
        if (
            mb_strlen($search) > 80 ||
            !mb_check_encoding($search, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new InvalidArgumentException("Use a bounded course search.");
        }
        $rows = $this->replacementCourses->page(trim($search), $after);
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }
    /** @return array<string,mixed> */
    public function replacementVersions(
        EntityId $student,
        EntityId $assignment,
        EntityId $course,
        ?string $cursor = null,
        ?EntityId $specialization = null,
    ): array {
        $this->authorize();
        $this->writable($this->curriculum->resolve($student, $assignment, new HistoryReadBudget(), $specialization));
        return $this->published->coursePage($course, $cursor);
    }
    /** The selected curriculum and course publication supply every matching rule reference.
     * @return array<string,mixed>
     */
    public function placementGroups(
        EntityId $student,
        EntityId $assignment,
        EntityId $course,
        EntityId $version,
        ?EntityId $specialization = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $course,
            $version,
            $specialization,
            $actor,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $parent = $this->curriculum->resolve($student, $assignment, $budget, $specialization);
            $this->writable($parent);
            $groups = $this->rules->placementGroups($course, $version, $parent["requirements"], $budget);
            $published = $this->published->courseVersion($course, $version, $budget);
            foreach ($groups as &$group) {
                $group["course_code"] = $published["code"];
                $group["course_title"] = $published["title"];
            }
            unset($group);
            $this->requirements->authorizeRead($student, $assignment);
            $this->assertActor($actor);
            return $published + [
                "placement_groups" => $groups,
                "requirements_name" => $parent["requirements"]["definition"]["name"],
            ];
        });
    }

    /**
     * @param array<mixed> $active
     * @param array<string,array<string,mixed>> $publications
     * @return array<string,array<string,mixed>> */
    private function substitutionDetails(
        array $active,
        ?HistoryReadBudget $budget = null,
        array &$publications = [],
    ): array {
        $budget ??= new HistoryReadBudget();
        $this->loadPublicationDetails($active, $budget, $publications);
        $out = [];
        foreach ($active as $p) {
            $d = $p["definition"];
            $reference = $this->publicationReference($d);
            if ($reference !== null) {
                $out[$p["adjustment_id"]] = $publications[$reference[0] . ":" . $reference[1]];
            }
        }
        return $out;
    }
    /**
     * @param array<mixed> $active
     * @param array<string,array<string,mixed>> $publications Request-local verified labels only.
     */
    private function loadPublicationDetails(array $active, HistoryReadBudget $budget, array &$publications): void
    {
        $requested = [];
        foreach ($active as $p) {
            $d = $p["definition"];
            $reference = $this->publicationReference($d);
            if ($reference === null) {
                continue;
            }
            [$course, $version] = $reference;
            if (!isset($publications[$course . ":" . $version])) {
                $requested[$course][$version] = new EntityId($version);
            }
        }
        foreach ($requested as $course => $versions) {
            $selected = $this->published->courseVersions(new EntityId($course), array_values($versions), $budget);
            foreach ($selected as $id => $detail) {
                $publications[$course . ":" . $id] = $detail;
            }
        }
    }

    /**
     * Original published labels shared by current approvals, routing and history.
     * @param array<string,mixed> $p
     * @param array<string,array<string,mixed>> $publications
     * @return array<string,string>
     */
    private function labels(array $p, array &$publications, HistoryReadBudget $budget): array
    {
        $key = "requirements:" . $p["requirements_version_id"];
        $publications[$key] ??= $this->published->version(
            new EntityId($p["template_id"]),
            new EntityId($p["requirements_version_id"]),
            $budget,
        );
        $source = $publications[$key];
        if (
            $source["program_id"] !== $p["program_id"] ||
            ($source["specialization_id"] ?? null) !== ($p["definition"]["component"]["specialization_id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $labels = [];
        $definition = $p["definition"];
        if ($definition["kind"] === "component_route") {
            $course = $this->published->courseVersion(
                new EntityId($definition["course_id"]),
                new EntityId($definition["course_version_id"]),
                $budget,
            );
            $target = $this->published->version(
                new EntityId($definition["target_template_id"]),
                new EntityId($definition["target_version_id"]),
                $budget,
            );
            if ($target["program_id"] !== $p["program_id"]) {
                throw new CorruptHistoryException();
            }
            $labels["subject_label"] = "Course routing · " . $course["code"] . " · " . $course["title"];
            $targetLabel = isset($target["specialization"])
            ? "Specialization " . $target["specialization"]["code"]
            : "Degree";
            $labels["scope_label"] = $targetLabel . " · " . $target["definition"]["name"];
        } elseif (in_array($definition["kind"], ["waiver", "substitution", "placement", "course_version"], true)) {
            $subjectGroups =
            $definition["kind"] === "placement"
            ? [$source["group_details"][$definition["group_id"]]]
            : $source["group_details"];
            foreach ($subjectGroups as $group) {
                $course = $group["course_details"][$definition["course_id"]] ?? null;
                if ($course !== null) {
                    $labels["subject_label"] =
                    ($definition["kind"] === "waiver"
                    ? "Course waiver · "
                    : ($definition["kind"] === "placement"
                                ? "Course placement · "
                                : ($definition["kind"] === "course_version"
                                    ? "Course publication acceptance · "
                                    : "Course substitution · "))) .
                    $course["code"] .
                    " · " .
                    $course["title"];
                    break;
                }
            }
            if (
                $definition["kind"] === "placement" &&
                (isset($definition["substitution_adjustment_id"]) || isset($definition["rule_ids"]))
            ) {
                $course = $this->published->courseVersion(
                    new EntityId($definition["course_id"]),
                    new EntityId($definition["course_version_id"]),
                    $budget,
                );
                $labels["subject_label"] = "Course placement · " . $course["code"] . " · " . $course["title"];
            }
            if ($definition["kind"] === "placement") {
                $labels["scope_label"] = $source["group_details"][$definition["group_id"]]["definition"]["name"];
            }
            if (in_array($definition["kind"], ["substitution", "course_version"], true)) {
                $isVersion = $definition["kind"] === "course_version";
                $course = $isVersion ? $definition["course_id"] : $definition["replacement_course_id"];
                $version = $isVersion ? $definition["version_id"] : $definition["replacement_version_id"];
                $replacement = $this->published->courseVersion(new EntityId($course), new EntityId($version), $budget);
                $labels["replacement_label"] = $replacement["code"] . " · " . $replacement["title"];
            }
            if ($labels === []) {
                throw new CorruptHistoryException();
            }
        } else {
            $labels["scope_label"] =
            $definition["group_id"] === null
            ? (isset($source["specialization"])
                        ? "Specialization " . $source["specialization"]["code"]
                        : "Degree")
            : $source["group_details"][$definition["group_id"]]["definition"]["name"];
        }
        if (isset($source["specialization"])) {
            $labels["component_label"] =
            "Specialization " . $source["specialization"]["code"] . " · " . $source["definition"]["name"];
        }
        return $labels;
    }

    /**
     * @param array<string,mixed> $definition
     * @return array{string,string}|null
     */
    private function publicationReference(array $definition): ?array
    {
        return match ($definition["kind"]) {
            "substitution" => [$definition["replacement_course_id"], $definition["replacement_version_id"]],
            "course_version" => [$definition["course_id"], $definition["version_id"]],
            "component_route" => [$definition["course_id"], $definition["course_version_id"]],
            "placement" => isset($definition["substitution_adjustment_id"]) || isset($definition["rule_ids"])
            ? [$definition["course_id"], $definition["course_version_id"]]
            : null,
            default => null,
        };
    }

    /**
     * Named choices remain separate from additional approved substitutes for placement only.
     * @param array<string,mixed> $parent
     * @param array<string,array<string,mixed>> $active
     * @param array<string,array<string,mixed>> $details
     * @param array<string,mixed> $effective
     * @return list<array<string,mixed>> */
    private function placementChoices(array $parent, array $active, array $details, array $effective): array
    {
        $choices = $this->choices($parent)["courses"];
        foreach ($active as $p) {
            $d = $p["definition"];
            if (
                $d["kind"] !== "substitution" ||
                !$this->applicable($parent, $p) ||
                !isset($effective["substitutions"][$d["course_id"]])
            ) {
                continue;
            }
            $course = $details[$p["adjustment_id"]];
            $groups = [];
            foreach ($parent["requirements"]["group_details"] as $id => $group) {
                $target = $group["course_details"][$d["course_id"]] ?? null;
                if ($target !== null) {
                    $groups[] = [
                        "id" => $id,
                        "name" => $group["definition"]["name"],
                        "course_code" => $course["code"],
                        "course_title" => $course["title"],
                        "required_course_code" => $target["code"],
                        "required_course_title" => $target["title"],
                    ];
                }
            }
            $choices[] = [
                "course_id" => $d["replacement_course_id"],
                "code" => $course["code"],
                "title" => $course["title"],
                "version" => $course["version"],
                "substitution_adjustment_id" => $p["adjustment_id"],
                "required_course_code" => $groups[0]["required_course_code"],
                "required_course_title" => $groups[0]["required_course_title"],
                "groups" => array_column($groups, "name"),
                "placement_groups" => $groups,
            ];
        }
        return $choices;
    }

    /** @param array<string,mixed> $effective */
    private function noSubstitutionConflicts(array $effective): void
    {
        if (($effective["substitution_conflicts"] ?? []) !== []) {
            throw new InvalidArgumentException(
                "Resolve the conflicting waiver or substitution first. " .
                    "Approve direct replacements without chains or cycles.",
            );
        }
    }
    private function authorize(): EntityId
    {
        $id = $this->access->requirePermission(self::PERMISSION);
        $this->access->requirePermission("sis:student:read");
        return $id;
    }
    /** @param array<string, mixed> $parent */
    private function writable(array $parent, bool $requireSelection = true): void
    {
        if ($requireSelection && $parent["selection"] === null) {
            throw new InvalidArgumentException(
                "Assign reviewed degree requirements before approving student exceptions.",
            );
        }
        if ($parent["scope"]["status"] !== "active" || $parent["cohort_changed"]) {
            throw new InvalidArgumentException(
                "Review the active student program and matriculation cohort before changing exceptions.",
            );
        }
    }
    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $r
     * @param array<string,array<string,mixed>> $active */
    private function proposal(array $parent, array $r, array $active): Definition
    {
        if (($r["kind"] ?? null) === "course_version") {
            $d = new Definition($r);
            $needed = false;
            foreach ($parent["requirements"]["group_details"] as $group) {
                foreach ($group["definition"]["courses"] as $reference) {
                    $accepted = [$reference["version_id"], ...$reference["accepted_version_ids"] ?? []];
                    if ($reference["course_id"] === $r["course_id"] && !in_array($r["version_id"], $accepted, true)) {
                        $needed = true;
                    }
                }
            }
            if (!$needed) {
                throw new InvalidArgumentException(
                    "Choose a curriculum course publication not already accepted in every group.",
                );
            }
            $this->published->courseVersion(new EntityId($r["course_id"]), new EntityId($r["version_id"]));
            return $d;
        }
        if (($r["kind"] ?? null) === "placement" && array_key_exists("rule_course_version_id", $r)) {
            Definition::keys($r, ["kind", "course_id", "group_id", "rule_course_version_id"]);
            foreach (["course_id", "group_id", "rule_course_version_id"] as $key) {
                if (!is_string($r[$key])) {
                    throw new InvalidArgumentException("Choose an explicit published rule course and matching group.");
                }
                new EntityId($r[$key]);
            }
            $groups = $this->rules->placementGroups(
                new EntityId($r["course_id"]),
                new EntityId($r["rule_course_version_id"]),
                $parent["requirements"],
                new HistoryReadBudget(),
            );
            foreach ($groups as $group) {
                if ($group["id"] === $r["group_id"]) {
                    return new Definition([
                        "kind" => "placement",
                        "course_id" => $r["course_id"],
                        "course_version_id" => $r["rule_course_version_id"],
                        "group_id" => $group["id"],
                        "group_version_id" => $group["version_id"],
                        "rule_ids" => array_column($group["rules"], "id"),
                    ]);
                }
            }
            throw new InvalidArgumentException(
                "Choose a group whose retained saved rules match that course publication.",
            );
        }
        if (($r["kind"] ?? null) === "placement") {
            $keys = ["kind", "course_id", "group_id"];
            if (array_key_exists("substitution_adjustment_id", $r)) {
                $keys[] = "substitution_adjustment_id";
            }
            Definition::keys($r, $keys);
            if (!is_string($r["course_id"]) || !is_string($r["group_id"])) {
                throw new InvalidArgumentException("Choose a course and its published group.");
            }
            $group = $parent["requirements"]["group_details"][$r["group_id"]] ?? null;
            if (array_key_exists("substitution_adjustment_id", $r)) {
                if (!is_string($r["substitution_adjustment_id"])) {
                    throw new InvalidArgumentException("Choose an active approved substitution.");
                }
                $source = $active[$r["substitution_adjustment_id"]] ?? null;
                if (
                    $source === null ||
                    ($source["definition"]["kind"] ?? null) !== "substitution" ||
                    !$this->applicable($parent, $source) ||
                    $source["definition"]["replacement_course_id"] !== $r["course_id"] ||
                    !isset($group["course_details"][$source["definition"]["course_id"]])
                ) {
                    throw new InvalidArgumentException("Choose an active substitute and a group requiring its target.");
                }
                return new Definition(
                    $r + [
                        "required_course_id" => $source["definition"]["course_id"],
                        "course_version_id" => $source["definition"]["replacement_version_id"],
                        "group_version_id" => $group["version_id"],
                    ],
                );
            }
            foreach ($group["definition"]["courses"] ?? [] as $course) {
                if ($course["course_id"] === $r["course_id"]) {
                    return new Definition(
                        $r + ["course_version_id" => $course["version_id"], "group_version_id" => $group["version_id"]],
                    );
                }
            }
            throw new InvalidArgumentException("Choose a course included in that retained curriculum group.");
        }
        if (in_array($r["kind"] ?? null, ["waiver", "substitution"], true)) {
            $d = new Definition($r);
            if ($this->affected($parent, $d) === []) {
                throw new InvalidArgumentException(
                    "Choose a course in this program’s selected published requirements.",
                );
            }
            if ($r["kind"] === "substitution") {
                $this->published->courseVersion(
                    new EntityId($r["replacement_course_id"]),
                    new EntityId($r["replacement_version_id"]),
                );
            }
            return $d;
        }
        Definition::keys($r, ["kind", "group_id", "minimum", "required_milli"]);
        foreach ($this->choices($parent)["targets"] as $target) {
            if ($r["group_id"] === $target["group_id"] && $r["minimum"] === $target["minimum"]) {
                return new Definition(
                    $r + [
                        "group_version_id" => $target["group_version_id"],
                        "original_milli" => $target["original_milli"],
                    ],
                );
            }
        }
        throw new InvalidArgumentException("Choose an existing degree or course-group minimum for this program.");
    }
    /**
     * @param array<string, mixed> $parent
     * @return array{targets:list<array<string,mixed>>,courses:list<array<string,mixed>>} */
    private function choices(array $parent): array
    {
        if ($parent["requirements"] === null) {
            return ["targets" => [], "courses" => []];
        }
        $req = $parent["requirements"];
        $targets = [];
        $courses = [];
        foreach (Definition::DEGREE_MINIMUMS as $key) {
            if (($req["definition"][$key] ?? 0) > 0) {
                $targets[] = [
                    "group_id" => null,
                    "group_version_id" => null,
                    "minimum" => $key,
                    "original_milli" => $req["definition"][$key],
                    "label" => isset($req["specialization"])
                        ? "Specialization " . $req["specialization"]["code"]
                        : "Degree",
                ];
            }
        }
        foreach ($req["group_details"] as $id => $g) {
            foreach (Definition::GROUP_MINIMUMS as $key) {
                if (($g["definition"][$key] ?? 0) > 0) {
                    $targets[] = [
                        "group_id" => $id,
                        "group_version_id" => $g["version_id"],
                        "minimum" => $key,
                        "original_milli" =>
                            $key === "min_courses" ? $g["definition"][$key] * 1000 : $g["definition"][$key],
                        "label" => $g["definition"]["name"],
                    ];
                }
            }
            foreach ($g["course_details"] as $course => $detail) {
                $courses[$course] ??= [
                    "course_id" => $course,
                    "code" => $detail["code"],
                    "title" => $detail["title"],
                    "groups" => [],
                    "placement_groups" => [],
                ];
                $courses[$course]["placement_groups"][] = [
                    "id" => $id,
                    "name" => $g["definition"]["name"],
                    "course_code" => $detail["code"],
                    "course_title" => $detail["title"],
                ];
                $courses[$course]["groups"][] = $g["definition"]["name"];
            }
        }
        return ["targets" => $targets, "courses" => array_values($courses)];
    }
    /**
     * @param array<string, mixed> $parent
     * @return list<string> */
    private function affected(array $parent, Definition $d): array
    {
        $groups = [];
        if ($d->definition["kind"] === "placement" && isset($d->definition["rule_ids"])) {
            return [$parent["requirements"]["group_details"][$d->definition["group_id"]]["definition"]["name"]];
        }
        foreach ($parent["requirements"]["group_details"] ?? [] as $id => $g) {
            if (
                (in_array($d->definition["kind"], ["waiver", "substitution", "placement", "course_version"], true) &&
                    isset($g["course_details"][$d->definition["required_course_id"] ?? $d->definition["course_id"]])) ||
                ($d->definition["kind"] === "minimum" && $d->definition["group_id"] === $id)
            ) {
                $groups[] = $g["definition"]["name"];
            }
        }
        return $groups;
    }
    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $p */
    private function applicable(array $parent, array $p): bool
    {
        if ($parent["selection"] === null) {
            return false;
        }
        if ($p["definition"]["kind"] === "component_route") {
            return false;
        }
        if ($p["definition"]["kind"] !== "waiver" && isset($p["definition"]["component"])) {
            return ($parent["specialization_id"] ?? null) === $p["definition"]["component"]["specialization_id"] &&
            $p["requirement_decision_id"] === $parent["selection"]["decision_id"] &&
            $p["template_id"] === $parent["selection"]["template_id"] &&
            $p["requirements_version_id"] === $parent["selection"]["requirements_version_id"] &&
            $p["definition"]["component"]["cohort_binding_revision"] ===
            $parent["selection"]["cohort_binding_revision"];
        }
        return $p["definition"]["kind"] === "waiver"
        ? $this->affected($parent, new Definition($p["definition"])) !== []
        : $p["template_id"] === $parent["selection"]["template_id"] &&
        $p["requirements_version_id"] === $parent["selection"]["requirements_version_id"];
    }
    /**
     * @param array<string, mixed> $parent
     * @param array<string, array<string, mixed>> $active
     * @param array<string,array<string,mixed>> $publications
     * @return array<string, mixed> */
    private function effective(
        array $parent,
        array $active,
        ?HistoryReadBudget $budget = null,
        array &$publications = [],
    ): array {
        if ($parent["requirements"] === null) {
            return ["degree" => [], "groups" => [], "waived_course_ids" => [], "substitutions" => []];
        }
        $budget ??= new HistoryReadBudget();
        $applicable = array_filter($active, fn(array $p): bool => $this->applicable($parent, $p));
        $this->loadPublicationDetails($applicable, $budget, $publications);
        $r = $parent["requirements"];
        $degree = array_intersect_key($r["definition"], array_flip(Definition::DEGREE_MINIMUMS));
        $groups = [];
        $waived = [];
        $substitutions = [];
        $acceptedVersions = [];
        $placements = [];
        $waiverApprovals = [];
        foreach ($active as $p) {
            if ($p["definition"]["kind"] === "waiver") {
                $waiverApprovals[$p["definition"]["course_id"]] = true;
            }
        }
        foreach ($r["group_details"] as $id => $g) {
            $groups[$id] = array_intersect_key(
                $g["definition"],
                array_flip([
                    ...Definition::GROUP_MINIMUMS,
                    "requirement_type",
                    "min_courses",
                    "min_course_grade_points_milli",
                    "max_course_grade_points_milli",
                ]),
            );
        }
        foreach ($active as $p) {
            if (!$this->applicable($parent, $p)) {
                continue;
            }
            $d = $p["definition"];
            if ($d["kind"] === "waiver") {
                $waived[] = $d["course_id"];
                continue;
            }
            if ($d["kind"] === "course_version") {
                if ($this->affected($parent, new Definition($d)) === []) {
                    throw new CorruptHistoryException();
                }
                $acceptedVersions[$d["course_id"]][$d["version_id"]] = true;
                continue;
            }
            if ($d["kind"] === "placement") {
                $group = $r["group_details"][$d["group_id"]] ?? null;
                $references = array_column($group["definition"]["courses"] ?? [], "version_id", "course_id");
                $replacement = isset($d["substitution_adjustment_id"]);
                $ruleCourse = isset($d["rule_ids"]);
                if ($ruleCourse) {
                    $matched = $this->rules->placementGroups(
                        new EntityId($d["course_id"]),
                        new EntityId($d["course_version_id"]),
                        $r,
                        $budget,
                    );
                    $found = false;
                    foreach ($matched as $candidate) {
                        if (
                            $candidate["id"] === $d["group_id"] &&
                            $candidate["version_id"] === $d["group_version_id"] &&
                            array_column($candidate["rules"], "id") === $d["rule_ids"]
                        ) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        throw new CorruptHistoryException();
                    }
                }
                if (
                    ($group["version_id"] ?? null) !== $d["group_version_id"] ||
                    (!$ruleCourse &&
                        ($replacement
                            ? !isset($references[$d["required_course_id"]])
                            : ($references[$d["course_id"]] ?? null) !== $d["course_version_id"]))
                ) {
                    throw new CorruptHistoryException();
                }
                $placements[$d["course_id"]] = [
                    "group_id" => $d["group_id"],
                    "group_version_id" => $d["group_version_id"],
                    "course_version_id" => $d["course_version_id"],
                ];
                continue;
            }
            if ($d["kind"] === "substitution") {
                if ($this->affected($parent, new Definition($d)) === []) {
                    throw new CorruptHistoryException();
                }
                $substitutions[$d["course_id"]] = [
                    "course_id" => $d["replacement_course_id"],
                    "version_id" => $d["replacement_version_id"],
                ];
                continue;
            }
            $base =
            $d["group_id"] === null
            ? $r["definition"][$d["minimum"]] ?? null
            : $r["group_details"][$d["group_id"]]["definition"][$d["minimum"]] ?? null;
            if ($d["minimum"] === "min_courses" && is_int($base)) {
                $base *= 1000;
            }
            if (
                $base !== $d["original_milli"] ||
                ($d["group_id"] !== null &&
                    ($r["group_details"][$d["group_id"]]["version_id"] ?? null) !== $d["group_version_id"])
            ) {
                throw new CorruptHistoryException();
            }
            if ($d["group_id"] === null) {
                $degree[$d["minimum"]] = $d["required_milli"];
            } else {
                $groups[$d["group_id"]][$d["minimum"]] =
                $d["minimum"] === "min_courses" ? intdiv($d["required_milli"], 1000) : $d["required_milli"];
            }
        }
        $conflicts = [];
        foreach ($substitutions as $course => $replacement) {
            if (isset($waiverApprovals[$course]) || isset($waiverApprovals[$replacement["course_id"]])) {
                $conflicts[$course] = "Resolve the overlapping course waiver and substitution.";
            }
            if (isset($substitutions[$replacement["course_id"]])) {
                $conflicts[$course] = "Choose a direct replacement; chained or circular substitutions need review.";
                $conflicts[$replacement["course_id"]] =
                "Choose a direct replacement; chained or circular substitutions need review.";
            }
        }
        foreach ($conflicts as $course => $message) {
            unset($substitutions[$course]);
        }
        if ($degree["min_resident_milli"] > $degree["min_credits_milli"]) {
            throw new InvalidArgumentException(
                "Resident credits cannot exceed total credits. Lower the resident minimum first, " .
                    "or restore total credits before revoking its related exception.",
            );
        }
        return [
            "degree" => $degree,
            "groups" => $groups,
            "waived_course_ids" => array_keys($waiverApprovals),
            "substitutions" => $substitutions,
            "accepted_course_versions" => $acceptedVersions,
            "placements" => $placements,
            "substitution_conflicts" => $conflicts,
        ];
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function review(
        EntityId $assignment,
        EntityId $actor,
        int $version,
        array $parent,
        string $action,
        array $p,
        #[\SensitiveParameter] string $reason,
        ?string $expiringKind = null,
    ): array {
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a reason of at most 1000 characters.");
        }
        $op = new EntityId($action === "grant" ? $p["adjustment_id"] : $p["decision_id"]);
        $context = new EventContext($actor, $this->clock->now(), $op, reason: $reason);
        $this->assertActor($actor);
        $d = [
            "kind" =>
                $action === "grant" && $p["definition"]["kind"] === "substitution"
                    ? "requirement-adjustment-review.v2"
                    : "requirement-adjustment-review.v1",
            "assignment_id" => $assignment->value,
            "actor_id" => $actor->value,
            "action" => $action,
            "payload" => $p,
            "selection_decision_id" => $parent["selection"]["decision_id"] ?? null,
            "expected_version" => $version,
            "reason" => $reason,
            "effective_at" => $context->describe()["effective_at"],
        ];
        $expiringKind ??= match ($p["definition"]["kind"] ?? null) {
            "placement" => "requirement-placement-review",
            "course_version" => "requirement-course-version-review",
            default => null,
        };
        if (
            isset($parent["specialization_id"]) ||
            ($p["definition"]["kind"] ?? null) === "component_route" ||
            ($action === "revoke" && $expiringKind === "requirement-curriculum-review")
        ) {
            $expiringKind = "requirement-curriculum-review";
        }
        if (isset($parent["specialization_id"])) {
            $d["specialization_id"] = $parent["specialization_id"];
        }
        if ($expiringKind !== null) {
            $d["kind"] = $expiringKind;
            $d["expires_at"] = $this->clock->now()->getTimestamp() + 1200;
        }
        return [
            "action" => $action,
            "adjustment" => $p,
            "reason" => $reason,
            "group_labels" => $this->groupLabels($parent),
            "grade_scale" => $parent["requirements"]["grade_scale"] ?? null,
            "review_token" => $this->cipher->seal(json_encode($d, JSON_THROW_ON_ERROR)),
        ];
    }
    /** @param array<string,mixed> $parent
     * @return array<string,string>
     */
    private function groupLabels(array $parent): array
    {
        $labels = [];
        foreach ($parent["requirements"]["group_details"] ?? [] as $id => $group) {
            $labels[$id] = $group["definition"]["name"];
        }
        return $labels;
    }
    private function assertActor(EntityId $actor): void
    {
        if ($this->authorize()->value !== $actor->value) {
            throw new AccessDeniedException("The student exception reviewer changed.");
        }
    }
    /**
     * @throws TypeException
     */
    private function stream(EntityId $assignment): StreamReference
    {
        return new StreamReference(new RequirementAdjustmentsId($assignment->value), "student_requirement_adjustments");
    }
    /**
     * @param array<string, mixed> $parent
     * @throws TypeException
     */
    private function state(
        EntityId $assignment,
        array $parent,
        ?HistoryReadBudget $budget = null,
    ): RequirementAdjustments {
        $budget ??= new HistoryReadBudget();
        $s = $this->stream($assignment);
        $state = RequirementAdjustments::root($s->aggregateId);
        $cursor = null;
        do {
            $page = $budget->page($this->history, $s, $cursor);
            foreach ($page->events as $stored) {
                $state->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $state->assertScope($parent["student_id"], $parent["scope"]["program_id"]);
        $rows = $this->projection->active($assignment);
        $expected = $state->active();
        ksort($rows);
        ksort($expected);
        if (!$this->same($rows, $expected)) {
            throw new CorruptHistoryException();
        }
        return $state;
    }
    /**
     * Compare typed projection facts independently of associative JSON key order.
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private function same(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right)) {
                return false;
            }
            if (is_array($value)) {
                if (!is_array($right[$key]) || !$this->same($value, $right[$key])) {
                    return false;
                }
            } elseif ($value !== $right[$key]) {
                return false;
            }
        }
        return true;
    }
}
