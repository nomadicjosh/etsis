<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use DateTimeImmutable;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Event\StudentRequirementsWereAssigned;
use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\DegreeAudit\Repository\StudentRequirementsRepository;
use Domain\DegreeAudit\StudentRequirements;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\PursuitStart;
use Domain\DegreeAudit\ValueObject\StudentRequirementsId;
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
use Throwable;

final readonly class StudentRequirementsService
{
    public const ASSIGN = "sis:degree-audit:assign";
    public function __construct(
        private StudentRequirementsRepository $selections,
        private StudentRecordRepository $records,
        private AdmissionsRepository $admissions,
        private CatalogYearRepository $catalogs,
        private RequirementsRepository $templates,
        private PublishedRequirementsReader $published,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }
    /** Authorize the pursuit scope without replaying its selected catalog publications. */
    public function authorizeRead(EntityId $student, EntityId $assignment): void
    {
        $this->scope($student, $assignment);
    }
    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        $this->scope($student, $assignment);
        return $this->transactions->run(function () use ($student, $assignment, $budget): array {
            $this->records->lock($student);
            $scope = $this->scope($student, $assignment);
            $state = $this->state($assignment, $budget);
            $selected = $state->selection();
            $requirements = null;
            $completion = null;
            if ($selected !== null) {
                $this->assertStudentScope($scope, $selected);
                $requirements = $this->published->version(
                    new EntityId($selected["template_id"]),
                    new EntityId($selected["requirements_version_id"]),
                    $budget,
                );
                if (
                    ($requirements['specialization_id'] ?? null) !== null ||
                    $requirements["program_id"] !== $scope["program_id"] ||
                    $requirements["academic_year_id"] !== $selected["academic_year_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $completion = new PursuitStart($selected["pursuit_started_on"])->anticipatedCompletion(
                    $requirements["definition"]["length_months"],
                );
            }
            return [
                "student_id" => $student->value,
                "assignment_id" => $assignment->value,
                "scope" => $scope,
                "selection" =>
                    $selected === null ? null : array_diff_key($selected, ["previous_decision_id" => true]),
                "requirements" => $requirements,
                "anticipated_completion_on" => $completion,
                "cohort_changed" =>
                    $selected !== null &&
                    ($scope["cohort_catalog_id"] !== $selected["cohort_catalog_id"] ||
                        $scope["cohort_binding_revision"] !== $selected["cohort_binding_revision"]),
            ];
        });
    }
    /** Internal calculation input: caller holds the student lock throughout this and academic collection.
     * Reads the selected policy envelope without resolving unused course groups.
     * @return array<string,mixed>|null
     */
    public function calculationContext(
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
    ): ?array {
        $scope = $this->scope($student, $assignment);
        $selected = $this->state($assignment, $budget)->selection();
        if ($selected === null) {
            return null;
        }
        $this->assertStudentScope($scope, $selected);
        $requirements = $this->published->calculationVersion(
            new EntityId($selected["template_id"]),
            new EntityId($selected["requirements_version_id"]),
            $budget,
        );
        if (
            ($requirements['specialization_id'] ?? null) !== null ||
            $requirements["program_id"] !== $scope["program_id"] ||
            $requirements["academic_year_id"] !== $selected["academic_year_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->scope($student, $assignment);
        return [
            "requirements" => $requirements,
            "cohort_changed" =>
                $scope["cohort_catalog_id"] !== $selected["cohort_catalog_id"] ||
                $scope["cohort_binding_revision"] !== $selected["cohort_binding_revision"],
        ];
    }
    /** Only the current matriculation cohort's published template is available for assignment.
     * @return array<string, mixed>
     */
    public function choices(EntityId $student, EntityId $assignment, ?string $cursor = null): array
    {
        $this->authorizeWrite();
        $scope = $this->scope($student, $assignment);
        $this->assertAssignable($scope);
        $row = $this->templates->find(
            new EntityId($scope["program_id"]),
            new EntityId($scope["academic_year_id"]),
        );
        if ($row === null) {
            return ["items" => [], "next_cursor" => null, "template_id" => null];
        }
        $page = $this->published->page(new EntityId($row["id"]), $cursor);
        foreach ($page["items"] as &$item) {
            $compatible =
            $item["program_version_id"] === null ||
            $item["program_version_id"] === $scope["cohort_catalog_id"];
            $item["assignable"] = $compatible && in_array($item["schema_version"], [3, 4], true);
            $item["assignment_note"] = !$compatible
            ? "This requirement version belongs to a different program catalog revision."
            : (!in_array($item["schema_version"], [3, 4], true)
                    ? "Publish an updated reusable-group composition before assigning this version."
                    : ($item["program_version_id"] === null
                        ? "No program-publication link was recorded for this version."
                        : "Matches the student’s assigned program catalog revision."));
        }
        unset($item);
        return $page + ["template_id" => $row["id"]];
    }
    /** @return array<string, mixed> */
    public function review(
        EntityId $student,
        EntityId $assignment,
        EntityId $template,
        EntityId $version,
        PursuitStart $start,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorizeWrite();
        if ($start->value > $this->clock->now()->format("Y-m-d")) {
            throw new InvalidArgumentException("The pursuit start date cannot be in the future.");
        }
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a reason of at most 1000 characters.");
        }
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $template,
            $version,
            $start,
            $reason,
            $actor,
        ): array {
            $this->records->lock($student);
            $scope = $this->scope($student, $assignment);
            $this->assertAssignable($scope);
            $requirements = $this->published->version($template, $version);
            $this->assertRequirements($scope, $requirements);
            $state = $this->state($assignment);
            $old = $state->selection();
            if ($old !== null) {
                $this->assertStudentScope($scope, $old);
            }
            $op = EntityId::generate();
            $context = new EventContext($actor, $this->clock->now(), $op, reason: $reason);
            $p = [
                "decision_id" => $op->value,
                "previous_decision_id" => $old["decision_id"] ?? null,
                "student_id" => $student->value,
                "program_id" => $scope["program_id"],
                "template_id" => $template->value,
                "requirements_version_id" => $version->value,
                "academic_year_id" => $scope["academic_year_id"],
                "cohort_catalog_id" => $scope["cohort_catalog_id"],
                "cohort_binding_revision" => $scope["cohort_binding_revision"],
                "pursuit_started_on" => $start->value,
            ];
            $data = [
                "kind" => "student-requirements-review.v1",
                "assignment_id" => $assignment->value,
                "actor_id" => $actor->value,
                "expected_version" => $state->playhead(),
                "scope" => $scope,
                "payload" => $p,
                "effective_at" => $context->describe()["effective_at"],
                "reason" => $reason,
            ];
            return [
                "selection" => array_diff_key($p, ["previous_decision_id" => true]),
                "requirements" => $requirements,
                "anticipated_completion_on" => $start->anticipatedCompletion(
                    $requirements["definition"]["length_months"],
                ),
                "reason" => $reason,
                "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            ];
        });
    }
    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function assign(
        EntityId $student,
        EntityId $assignment,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorizeWrite();
        $this->scope($student, $assignment);
        try {
            if (strlen($token) > 32768) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                ($data["kind"] ?? null) !== "student-requirements-review.v1" ||
                ($data["assignment_id"] ?? null) !== $assignment->value ||
                ($data["payload"]["student_id"] ?? null) !== $student->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review the student requirement assignment again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $assignment, $data, $actor): array {
            $this->records->lock($student);
            $scope = $this->scope($student, $assignment);
            $state = $this->state($assignment);
            if ($state->selection() !== null) {
                $this->assertStudentScope($scope, $state->selection());
            }
            $p = $data["payload"];
            $op = new EntityId($p["decision_id"]);
            $event = StudentRequirementsWereAssigned::occur($this->stream($assignment)->aggregateId, $p, [
                "__aggregate_type" => "student_degree_requirements",
            ])->withPlayhead($data["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream($assignment),
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
                $this->assertAssignable($scope);
                if ($scope !== $data["scope"]) {
                    throw new ConcurrentModificationException(
                        "The student program or cohort changed. Review the assignment again.",
                    );
                }
                $this->assertRequirements(
                    $scope,
                    $this->published->version(
                        new EntityId($p["template_id"]),
                        new EntityId($p["requirements_version_id"]),
                    ),
                );
                $this->selections->project($assignment, $p, $receipt["last_version"]);
                $this->audit->record($actor, "degree_audit.student_requirements_assigned", $assignment);
            }
            return $receipt;
        });
    }
    /** Curated decision references. Protected reasons and actors are deliberately absent.
     * @return array<string, mixed>
     */
    public function history(EntityId $student, EntityId $assignment, ?string $cursor = null): array
    {
        $scope = $this->scope($student, $assignment);
        $page = $this->history->page($this->stream($assignment), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $this->assertStudentScope($scope, $p);
            $items[] = array_diff_key($p, ["previous_decision_id" => true]) + [
                "version" => $stored->event->playhead(),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "effective_at" => $stored->context->describe()["effective_at"],
            ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    private function authorizeWrite(): EntityId
    {
        $actor = $this->access->requirePermission(self::ASSIGN);
        $this->access->requirePermission("sis:student:read");
        return $actor;
    }
    /** @return array<string, mixed> */
    private function scope(EntityId $student, EntityId $assignment): array
    {
        $this->access->authenticatedUserId();
        $record = $this->admissions->student($student);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($record->personId)->ownerUserId,
            "sis:student:read",
        );
        $a = $this->records->assignment($assignment);
        if ($a->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        $b = $this->catalogs->binding($assignment);
        $release = $b === null ? null : $this->catalogs->release(new EntityId($b["catalog_id"]));
        if ($release !== null && $release["program_id"] !== $a->programId->value) {
            throw new CorruptHistoryException();
        }
        return [
            "student_id" => $student->value,
            "program_id" => $a->programId->value,
            "entry_term_id" => $a->entryTermId->value,
            "status" => $a->status,
            "academic_year_id" => $release["year_id"] ?? null,
            "cohort_catalog_id" => $b["catalog_id"] ?? null,
            "cohort_binding_revision" => $b["version"] ?? null,
        ];
    }
    /** @param array<string, mixed> $scope */
    private function assertAssignable(array $scope): void
    {
        if ($scope["status"] !== "active") {
            throw new InvalidArgumentException(
                "Reopen this program assignment before changing its degree requirements.",
            );
        }
        if ($scope["academic_year_id"] === null) {
            throw new InvalidArgumentException(
                "Review this program’s matriculation cohort before assigning requirements.",
            );
        }
    }
    /** @param array<string, mixed> $scope
     * @param array<string, mixed> $requirements */
    private function assertRequirements(array $scope, array $requirements): void
    {
        if (($requirements['specialization_id'] ?? null) !== null) {
            throw new InvalidArgumentException(
                'Specialization requirements cannot replace a student’s degree baseline.',
            );
        }
        if (
            ($requirements["program_version_id"] ?? null) !== null &&
            $requirements["program_version_id"] !== $scope["cohort_catalog_id"]
        ) {
            throw new InvalidArgumentException(
                "Choose requirements published for this student's assigned program catalog revision. " .
                    "Changing a cohort requires its own reviewed decision.",
            );
        }
        if (
            $requirements["program_id"] !== $scope["program_id"] ||
            $requirements["academic_year_id"] !== $scope["academic_year_id"]
        ) {
            throw new InvalidArgumentException(
                "Choose published requirements for this program and its matriculation cohort.",
            );
        }
        if (!in_array($requirements["schema_version"], [3, 4], true)) {
            throw new InvalidArgumentException(
                "Publish a degree composition with explicit reusable groups and policies before assigning it.",
            );
        }
        new AuditRequirementsV3($requirements["definition"])->assertPublishable();
    }
    /** @param array<string, mixed> $scope
     * @param array<string, mixed> $selected */
    private function assertStudentScope(array $scope, array $selected): void
    {
        if (
            $scope["student_id"] !== $selected["student_id"] ||
            $scope["program_id"] !== $selected["program_id"]
        ) {
            throw new CorruptHistoryException();
        }
    }
    private function stream(EntityId $assignment): StreamReference
    {
        return new StreamReference(
            new StudentRequirementsId($assignment->value),
            "student_degree_requirements",
        );
    }
    private function state(EntityId $assignment, ?HistoryReadBudget $budget = null): StudentRequirements
    {
        $s = $this->stream($assignment);
        $state = StudentRequirements::root($s->aggregateId);
        $cursor = null;
        do {
            $page =
            $budget === null
            ? $this->history->page($s, $cursor, 20)
            : $budget->page($this->history, $s, $cursor);
            foreach ($page->events as $stored) {
                $state->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $p = $state->selection();
        $row = $this->selections->get($assignment);
        if (($p === null) !== ($row === null)) {
            throw new CorruptHistoryException();
        }
        if ($p !== null && $row !== null) {
            unset($p["previous_decision_id"]);
            $expected = ["id" => $assignment->value] + $p + ["position" => $state->playhead()];
            if (count($row) !== count($expected)) {
                throw new CorruptHistoryException();
            }
            foreach ($expected as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        return $state;
    }
}
