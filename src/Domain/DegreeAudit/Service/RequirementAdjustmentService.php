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
    ) {
    }
    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $assignment): array
    {
        return $this->read($student, $assignment, false);
    }
    /** Selected publication and verified approvals from the same locked read; no completion calculation.
     * @return array<string,mixed>
     */
    public function preview(EntityId $student, EntityId $assignment): array
    {
        return $this->read($student, $assignment, true);
    }
    /** @return array<string,mixed> */
    private function read(EntityId $student, EntityId $assignment, bool $includeRequirements): array
    {
        $this->requirements->authorizeRead($student, $assignment);
        return $this->transactions->run(function () use ($student, $assignment, $includeRequirements): array {
            $this->students->lock($student);
            $parent = $this->requirements->view($student, $assignment);
            $state = $this->state($assignment, $parent);
            $choices = $this->choices($parent);
            $effective = $this->effective($parent, $state->active());
            $items = [];
            foreach ($state->active() as $p) {
                $items[] = $p + ["applicable" => $this->applicable($parent, $p)];
            }
            return [
                "items" => $items,
                "targets" => $choices["targets"],
                "courses" => $choices["courses"],
                "effective_minimums" => $effective,
                "group_labels" => $this->groupLabels($parent),
                "grade_scale" => $parent["requirements"]["grade_scale"] ?? null,
                "selection" => $parent["selection"],
                "scope" => $parent["scope"],
                "cohort_changed" => $parent["cohort_changed"],
                "substitution_details" => $this->substitutionDetails($state->active()),
            ] + ($includeRequirements ? ["requirements" => $parent["requirements"]] : []);
        });
    }
    /** @param array<string, mixed> $request
     * @return array<string, mixed> */
    public function reviewGrant(
        EntityId $student,
        EntityId $assignment,
        array $request,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $request,
            $reason,
            $actor,
        ): array {
            $this->students->lock($student);
            $parent = $this->requirements->view($student, $assignment);
            $this->writable($parent);
            $state = $this->state($assignment, $parent);
            $definition = $this->proposal($parent, $request);
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
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $adjustment,
            $reason,
            $actor,
        ): array {
            $this->students->lock($student);
            $parent = $this->requirements->view($student, $assignment);
            $this->writable($parent);
            $state = $this->state($assignment, $parent);
            $active = $state->active();
            $existing =
                $active[$adjustment->value] ??
                throw new RecordNotFoundException("Active student exception not found.");
            unset($active[$adjustment->value]);
            $effective = $this->effective($parent, $active);
            $p = [
                "adjustment_id" => $adjustment->value,
                "decision_id" => EntityId::generate()->value,
                "student_id" => $student->value,
                "program_id" => $parent["scope"]["program_id"],
            ];
            return $this->review($assignment, $actor, $state->playhead(), $parent, "revoke", $p, $reason) + [
                "previous" => $existing,
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
                    ["requirement-adjustment-review.v1", "requirement-adjustment-review.v2"],
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
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This exception review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $assignment, $actor, $d): array {
            $this->students->lock($student);
            $parent = $this->requirements->view($student, $assignment);
            $state = $this->state($assignment, $parent);
            $this->assertActor($actor);
            $p = $d["payload"];
            $op = new EntityId($d["action"] === "grant" ? $p["adjustment_id"] : $p["decision_id"]);
            $class =
                $d["action"] === "grant"
                    ? RequirementAdjustmentWasGranted::class
                    : RequirementAdjustmentWasRevoked::class;
            $metadata = ["__aggregate_type" => "student_requirement_adjustments"];
            if ($d["action"] === "grant" && $p["definition"]["kind"] === "substitution") {
                if ($d["kind"] !== "requirement-adjustment-review.v2") {
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
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    $op,
                    reason: $d["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                $this->writable($parent);
                if ($parent["selection"]["decision_id"] !== $d["selection_decision_id"]) {
                    throw new ConcurrentModificationException(
                        "The selected degree requirements changed. Review this exception again.",
                    );
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
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            if ($p["student_id"] !== $student->value || $p["program_id"] !== $parent["scope"]["program_id"]) {
                throw new CorruptHistoryException();
            }
            $labels = [];
            if ($stored->event instanceof RequirementAdjustmentWasGranted) {
                $version = $p["requirements_version_id"];
                $publications[$version] ??=
                $parent["selection"] !== null &&
                $parent["selection"]["requirements_version_id"] === $version
                ? $parent["requirements"]
                : $this->published->version(new EntityId($p["template_id"]), new EntityId($version));
                $source = $publications[$version];
                if ($source["program_id"] !== $p["program_id"]) {
                    throw new CorruptHistoryException();
                }
                $definition = $p["definition"];
                if (in_array($definition["kind"], ["waiver", "substitution"], true)) {
                    foreach ($source["group_details"] as $group) {
                        $course = $group["course_details"][$definition["course_id"]] ?? null;
                        if ($course !== null) {
                            $labels["subject_label"] =
                            ($definition["kind"] === "waiver"
                            ? "Course waiver · "
                            : "Course substitution · ") .
                            $course["code"] .
                            " · " .
                            $course["title"];
                            break;
                        }
                    }
                    if ($definition["kind"] === "substitution") {
                        $replacement = $this->published->courseVersion(
                            new EntityId($definition["replacement_course_id"]),
                            new EntityId($definition["replacement_version_id"]),
                        );
                        $labels["replacement_label"] = $replacement["code"] . " · " . $replacement["title"];
                    }
                    if ($labels === []) {
                        throw new CorruptHistoryException();
                    }
                } else {
                    $labels["scope_label"] =
                    $definition["group_id"] === null
                    ? "Degree"
                    : $source["group_details"][$definition["group_id"]]["definition"]["name"];
                }
            }
            $items[] = $p +
            $labels + [
                    "action" =>
                        $stored->event instanceof RequirementAdjustmentWasGranted ? "grant" : "revoke",
                    "version" => $stored->event->playhead(),
                    "effective_at" => $stored->context->describe()["effective_at"],
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /** Minimal current catalog search, followed by explicit verified publication selection.
     * @return array<string,mixed> */
    public function replacementCourses(
        EntityId $student,
        EntityId $assignment,
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $this->authorize();
        $this->writable($this->requirements->view($student, $assignment));
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
    ): array {
        $this->authorize();
        $this->writable($this->requirements->view($student, $assignment));
        return $this->published->coursePage($course, $cursor);
    }
    /** @param array<mixed> $active
     * @return array<string,array<string,mixed>> */
    private function substitutionDetails(array $active): array
    {
        $out = [];
        foreach ($active as $p) {
            $d = $p["definition"];
            if ($d["kind"] === "substitution") {
                $out[$p["adjustment_id"]] = $this->published->courseVersion(
                    new EntityId($d["replacement_course_id"]),
                    new EntityId($d["replacement_version_id"]),
                );
            }
        }
        return $out;
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
    private function writable(array $parent): void
    {
        if ($parent["selection"] === null) {
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
    /** @param array<string, mixed> $parent
     * @param array<string, mixed> $r */
    private function proposal(array $parent, array $r): Definition
    {
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
        throw new InvalidArgumentException(
            "Choose an existing degree or course-group minimum for this program.",
        );
    }
    /** @param array<string, mixed> $parent
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
                    "label" => "Degree",
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
                ];
                $courses[$course]["groups"][] = $g["definition"]["name"];
            }
        }
        return ["targets" => $targets, "courses" => array_values($courses)];
    }
    /** @param array<string, mixed> $parent
     * @return list<string> */
    private function affected(array $parent, Definition $d): array
    {
        $groups = [];
        foreach ($parent["requirements"]["group_details"] ?? [] as $id => $g) {
            if (
                (in_array($d->definition["kind"], ["waiver", "substitution"], true) &&
                    isset($g["course_details"][$d->definition["course_id"]])) ||
                ($d->definition["kind"] === "minimum" && $d->definition["group_id"] === $id)
            ) {
                $groups[] = $g["definition"]["name"];
            }
        }
        return $groups;
    }
    /** @param array<string, mixed> $parent
     * @param array<string, mixed> $p */
    private function applicable(array $parent, array $p): bool
    {
        if ($parent["selection"] === null) {
            return false;
        }
        return $p["definition"]["kind"] === "waiver"
        ? $this->affected($parent, new Definition($p["definition"])) !== []
        : $p["template_id"] === $parent["selection"]["template_id"] &&
        $p["requirements_version_id"] === $parent["selection"]["requirements_version_id"];
    }
    /** @param array<string, mixed> $parent
     * @param array<string, array<string, mixed>> $active
     * @return array<string, mixed> */
    private function effective(array $parent, array $active): array
    {
        if ($parent["requirements"] === null) {
            return ["degree" => [], "groups" => [], "waived_course_ids" => [], "substitutions" => []];
        }
        $r = $parent["requirements"];
        $degree = array_intersect_key($r["definition"], array_flip(Definition::DEGREE_MINIMUMS));
        $groups = [];
        $waived = [];
        $substitutions = [];
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
            if ($d["kind"] === "substitution") {
                if ($this->affected($parent, new Definition($d)) === []) {
                    throw new CorruptHistoryException();
                }
                $this->published->courseVersion(
                    new EntityId($d["replacement_course_id"]),
                    new EntityId($d["replacement_version_id"]),
                );
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
                $d["minimum"] === "min_courses"
                ? intdiv($d["required_milli"], 1000)
                : $d["required_milli"];
            }
        }
        $conflicts = [];
        foreach ($substitutions as $course => $replacement) {
            if (isset($waiverApprovals[$course]) || isset($waiverApprovals[$replacement["course_id"]])) {
                $conflicts[$course] = "Resolve the overlapping course waiver and substitution.";
            }
            if (isset($substitutions[$replacement["course_id"]])) {
                $conflicts[$course] =
                "Choose a direct replacement; chained or circular substitutions need review.";
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
            "waived_course_ids" => array_values(array_unique($waived)),
            "substitutions" => $substitutions,
            "substitution_conflicts" => $conflicts,
        ];
    }
    /** @param array<string, mixed> $parent
     * @param array<string, mixed> $p
     * @return array<string, mixed> */
    private function review(
        EntityId $assignment,
        EntityId $actor,
        int $version,
        array $parent,
        string $action,
        array $p,
        #[\SensitiveParameter] string $reason,
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
            "selection_decision_id" => $parent["selection"]["decision_id"],
            "expected_version" => $version,
            "reason" => $reason,
            "effective_at" => $context->describe()["effective_at"],
        ];
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
    private function stream(EntityId $assignment): StreamReference
    {
        return new StreamReference(
            new RequirementAdjustmentsId($assignment->value),
            "student_requirement_adjustments",
        );
    }
    /** @param array<string, mixed> $parent */
    private function state(EntityId $assignment, array $parent): RequirementAdjustments
    {
        $s = $this->stream($assignment);
        $state = RequirementAdjustments::root($s->aggregateId);
        $cursor = null;
        do {
            $page = $this->history->page($s, $cursor, 20);
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
    /** Compare typed projection facts independently of associative JSON key order.
     * @param array<mixed> $left
     * @param array<mixed> $right */
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
