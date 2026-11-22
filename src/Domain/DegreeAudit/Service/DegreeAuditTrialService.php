<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;
use JsonException;

/** Runtime-only curriculum trial. No publication, assignment, approval or result persistence. */
final readonly class DegreeAuditTrialService
{
    public function __construct(
        private RequirementsRepository $templates,
        private CourseGroupRepository $groups,
        private PublishedRequirementsReader $published,
        private PublishedCourseReader $courses,
        private GradingScaleService $scales,
        private StudentRequirementsService $requirements,
        private AcademicInputService $inputs,
        private DegreeProgressEvaluator $evaluator,
        private StudentRecordRepository $students,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private CombinedAllocationEvaluator $combined,
        private DegreeProgressService $assignedProgress,
    ) {
    }

    /** Test an editor snapshot, optionally alongside saved specialization sources.
     * @param array<array-key,mixed> $companions Requested sources, validated after authorization.
     * @return array<string,mixed>
     */
    public function test(
        EntityId $template,
        EntityId $student,
        EntityId $assignment,
        AuditRequirementsV3 $definition,
        bool $useGroupDrafts = false,
        ?HistoryReadBudget $budget = null,
        array $companions = [],
        ?ComponentCourseSharing $sharing = null,
    ): array {
        return $this->run(
            $student,
            $assignment,
            fn(HistoryReadBudget $budget): array => $this->draftSource(
                $template,
                $definition,
                $useGroupDrafts,
                $budget,
            ),
            $budget,
            $companions,
            $sharing,
        );
    }

    /** Exact publications never use current definitions as substitutes.
     * @param array<array-key,mixed> $companions Requested sources, validated after authorization.
     * @return array<string,mixed>
     */
    public function testPublication(
        EntityId $template,
        EntityId $version,
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget = null,
        array $companions = [],
        ?ComponentCourseSharing $sharing = null,
        bool $includeExceptions = false,
    ): array {
        if ($includeExceptions) {
            return $this->assignedReview($template, $version, $student, $assignment, $budget, $companions, $sharing);
        }
        return $this->run(
            $student,
            $assignment,
            fn(HistoryReadBudget $budget): array => $this->publicationSource($template, $version, $budget),
            $budget,
            $companions,
            $sharing,
        );
    }

    /**
     * Reuse the canonical assigned-pursuit reader; never transplant approvals into hypothetical inputs.
     * @param array<array-key,mixed> $companions
     * @return array<string,mixed>
     */
    private function assignedReview(
        EntityId $template,
        EntityId $version,
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget,
        array $companions,
        ?ComponentCourseSharing $sharing,
    ): array {
        $actor = $this->authorize($student, $assignment);
        $mode = $companions === [] ? 'runtime_test' : 'runtime_combined_test';
        try {
            return $this->transactions->run(function () use (
                $template,
                $version,
                $student,
                $assignment,
                $budget,
                $companions,
                $sharing,
                $actor,
                $mode,
            ): array {
                $this->students->lock($student);
                $this->authorize($student, $assignment, $actor);
                $budget ??= new HistoryReadBudget();
                if (!array_is_list($companions) || count($companions) > 24 || $sharing !== null) {
                    self::exceptionScope('Use exact assigned publications without a sharing-policy override.');
                }
                foreach ($companions as $choice) {
                    if (!is_array($choice) || ($choice['source'] ?? null) !== 'publication') {
                        self::exceptionScope('Approved exceptions cannot be applied to specialization drafts.');
                    }
                }
                $source = $this->publicationSource($template, $version, $budget);
                $sources = $companions === [] ? [$source]
                    : [$source, ...$this->companionSources($source, $companions, $budget)];
                $combined = $companions !== [];
                $result = $combined
                    ? $this->assignedProgress->combined($student, $assignment, $budget)
                    : ($source['specialization'] === null
                        ? $this->assignedProgress->preview($student, $assignment, $budget)
                        : $this->assignedProgress->specialization(
                            $student,
                            $assignment,
                            new EntityId($source['specialization']['id']),
                            $budget,
                        ));
                if ($result['status'] !== 'review') {
                    $this->authorize($student, $assignment, $actor);
                    return array_replace($result, [
                        'mode' => $mode, 'exceptions_applied' => false, 'exception_mode' => 'assigned_publications',
                    ]);
                }
                $requested = array_column($sources, null, 'version_id');
                $parts = $combined ? $result['components'] : [['progress' => $result]];
                if (count($parts) !== count($requested)) {
                    self::exceptionScope('Choose every assigned specialization publication for this combined test.');
                }
                foreach ($parts as $part) {
                    $context = $part['progress']['context'];
                    if (!isset($requested[$context['requirements_version_id']]) || $context['cohort_changed']) {
                        self::exceptionScope(
                            'Choose the exact publication assigned to this student pursuit. ' .
                                'Review changed cohorts first.',
                        );
                    }
                }
                if ($combined) {
                    foreach ($result['components'] as &$component) {
                        $source = $requested[$component['progress']['context']['requirements_version_id']];
                        $component['progress']['context']['test_source'] = 'publication';
                        $component['progress'] = self::sourceReview($component['progress'], $source, true);
                    }
                    unset($component);
                    $result['sharing_source'] = 'published_program';
                } else {
                    $result['context'] = array_replace($result['context'], [
                        'mode' => 'runtime_test', 'source' => 'publication', 'exceptions_applied' => true,
                        'program_code' => $source['program_code'], 'program_title' => $source['program_title'],
                    ]);
                    $result = self::sourceReview($result, $source, true);
                }
                $this->authorize($student, $assignment, $actor);
                return array_replace($result, [
                    'mode' => $mode, 'exceptions_applied' => true, 'exception_mode' => 'assigned_publications',
                ]);
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = 'limited';
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $status = 'inconsistent';
        }
        $this->authorize($student, $assignment, $actor);
        return ['status' => $status, 'mode' => $mode, 'exceptions_applied' => false,
            'exception_mode' => 'assigned_publications'];
    }

    private static function exceptionScope(string $message): never
    {
        throw new \Domain\Shared\Exception\ValidationException([
            ['field' => 'include_exceptions', 'message' => $message],
        ]);
    }

    /**
     * Shared captured-source annotation for baseline and assigned-publication tests.
     * @param array<string,mixed> $progress
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     * @throws JsonException
     */
    private static function sourceReview(array $progress, array $source, bool $exceptions = false): array
    {
        $progress['sources'] = $source['sources'];
        $progress['definition_hash'] = hash('sha256', json_encode($source['requirements'], JSON_THROW_ON_ERROR));
        if ($exceptions) {
            $progress['context']['exceptions_applied'] = true;
        }
        return $progress;
    }

    /** @return array<string,mixed> */
    private function draftSource(
        EntityId $template,
        AuditRequirementsV3 $definition,
        bool $useGroupDrafts,
        HistoryReadBudget $budget,
    ): array {
        if ($definition->definition["groups"] === []) {
            throw new InvalidArgumentException("Add a course group before testing this degree draft.");
        }
        $row = $this->templates->get($template);
        $context = $this->templates->context(new EntityId($row["program_id"]), new EntityId($row["academic_year_id"]));
        [$groups, $sources] = $this->groupInputs($definition, $useGroupDrafts, $budget);
        return [
            "template_id" => $template->value,
            "program_id" => $row["program_id"],
            "academic_year_id" => $row["academic_year_id"],
            "source" => "draft",
            "requirements" => [
                "definition" => $definition->definition,
                "group_details" => $groups,
                "grade_scale" => $this->scales->resolve(new EntityId($row["program_id"]), $budget),
            ],
            "academic_year_code" => $context["academic_year_code"],
            "program_code" => $context["program_code"],
            "program_title" => $context["program_title"],
            "sources" => $sources,
            "version" => null,
            "version_id" => null,
            "specialization" =>
                ($row["specialization_id"] ?? null) === null
                    ? null
                    : $this->templates->specialization(
                        new EntityId($row["program_id"]),
                        new EntityId($row["specialization_id"]),
                    ),
        ];
    }

    /** @return array<string,mixed> */
    private function publicationSource(EntityId $template, EntityId $version, HistoryReadBudget $budget): array
    {
        $requirements = $this->published->version($template, $version, $budget);
        $sources = [];
        foreach ($requirements["group_details"] as $id => $group) {
            $sources[] = [
                "group_id" => $id,
                "name" => $group["definition"]["name"],
                "source" => "publication",
                "revision" => $group["version"],
            ];
        }
        return [
            "template_id" => $template->value,
            "program_id" => $requirements["program_id"],
            "academic_year_id" => $requirements["academic_year_id"],
            "source" => "publication",
            "requirements" => $requirements,
            "academic_year_code" => $requirements["context"]["academic_year_code"],
            "program_code" => $requirements["context"]["program_code"],
            "program_title" => $requirements["context"]["program_title"],
            "sources" => $sources,
            "version" => $requirements["version"],
            "version_id" => $version->value,
            "specialization" => $requirements["specialization"] ?? null,
        ];
    }

    /**
     * Caller-supplied source resolution is internal; HTTP clients cannot supply academic inputs.
     * @param callable(HistoryReadBudget):array<string,mixed> $resolve
     * @param array<array-key,mixed> $companions Requested sources, validated after authorization.
     * @return array<string,mixed>
     */
    private function run(
        EntityId $student,
        EntityId $assignment,
        callable $resolve,
        ?HistoryReadBudget $budget = null,
        array $companions = [],
        ?ComponentCourseSharing $sharing = null,
    ): array {
        $actor = $this->authorize($student, $assignment);
        try {
            return $this->transactions->run(function () use (
                $student,
                $assignment,
                $resolve,
                $budget,
                $actor,
                $companions,
                $sharing,
            ): array {
                $this->students->lock($student);
                $this->authorize($student, $assignment, $actor);
                $budget ??= new HistoryReadBudget();
                if (
                    !array_is_list($companions) ||
                    count($companions) > 24 ||
                    ($companions === []) !== ($sharing === null)
                ) {
                    throw new InvalidArgumentException(
                        "Choose specialization sources and an explicit test sharing policy together.",
                    );
                }
                $source = $resolve($budget);
                $sources = $companions === [] ? [] : $this->companionSources($source, $companions, $budget);
                $requirements = $source["requirements"];
                $needsStart = GroupWorkEligibility::requiresPursuitStart($requirements);
                foreach ($sources as $companion) {
                    $needsStart = $needsStart || GroupWorkEligibility::requiresPursuitStart(
                        $companion['requirements']
                    );
                }
                $start = $needsStart ? $this->requirements->pursuitStartedOn($student, $assignment, $budget) : null;
                $effective = DegreeProgressEvaluator::unadjusted($requirements, $start);
                $inputs = $this->inputs->collect($student, $assignment, $budget);
                if ($inputs->status === AcademicInputStatus::Limited) {
                    throw new HistoryReadLimitException();
                }
                if ($inputs->status === AcademicInputStatus::Inconsistent) {
                    throw new CorruptHistoryException();
                }
                if ($sources !== []) {
                    $result = $this->combinedReview(
                        $inputs,
                        [$source, ...$sources],
                        $sharing ?? throw new InvalidArgumentException("Choose a test sharing policy."),
                        $budget,
                        $start,
                    );
                    $this->authorize($student, $assignment, $actor);
                    return $result;
                }
                $result = $this->evaluator->evaluate($inputs, $requirements, $effective, $budget);
                $this->authorize($student, $assignment, $actor);
                return $result + [
                    "mode" => "runtime_test",
                    "context" => [
                        "mode" => "runtime_test",
                        "source" => $source["source"],
                        "name" => $requirements["definition"]["name"],
                        "academic_year_code" => $source["academic_year_code"],
                        "program_code" => $source["program_code"],
                        "program_title" => $source["program_title"],
                        "group_reuse" => $requirements["definition"]["policies"]["group_reuse"] ?? null,
                        "cohort_changed" => false,
                        "version" => $source["version"],
                        "requirements_version_id" => $source["version_id"],
                        "specialization" => $source["specialization"] ?? null,
                    ],
                    "sources" => $source["sources"],
                    "definition_hash" => hash("sha256", json_encode($requirements, JSON_THROW_ON_ERROR)),
                    "exceptions_applied" => false,
                ];
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = "limited";
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $status = "inconsistent";
        }
        $this->authorize($student, $assignment, $actor);
        return ["status" => $status, "mode" => $companions === [] ? "runtime_test" : "runtime_combined_test"];
    }

    /**
     * @param array<string,mixed> $degree
     * @param list<mixed> $choices
     * @return list<array<string,mixed>>
     * @throws JsonException
     */
    private function companionSources(array $degree, array $choices, HistoryReadBudget $budget): array
    {
        if ($degree["specialization"] !== null) {
            throw new InvalidArgumentException("Start a combined test from a degree audit.");
        }
        $sources = $seen = [];
        foreach ($choices as $choice) {
            if (
                !is_array($choice) ||
                !is_string($choice["template_id"] ?? null) ||
                !in_array($choice["source"] ?? null, ["draft", "publication"], true)
            ) {
                throw new InvalidArgumentException("Choose a saved specialization draft or an exact publication.");
            }
            $draft = $choice["source"] === "draft";
            $allowed = $draft ? ["template_id", "source", "use_group_drafts"] : ["template_id", "source", "version_id"];
            if (
                array_diff(array_keys($choice), $allowed) !== [] ||
                ($draft && !is_bool($choice["use_group_drafts"] ?? false)) ||
                (!$draft && !is_string($choice["version_id"] ?? null))
            ) {
                throw new InvalidArgumentException("Specialization sources cannot override published inputs.");
            }
            $template = new EntityId($choice["template_id"]);
            $row = $this->templates->get($template);
            $specialization = $row["specialization_id"] ?? null;
            if (
                $row["program_id"] !== $degree["program_id"] ||
                $row["academic_year_id"] !== $degree["academic_year_id"] ||
                $specialization === null ||
                isset($seen[$specialization])
            ) {
                throw new InvalidArgumentException(
                    "Choose each specialization once from this degree program and academic year.",
                );
            }
            $seen[$specialization] = true;
            $resolved = $draft
            ? $this->draftSource(
                $template,
                new AuditRequirementsV3($row["definition"]),
                $choice["use_group_drafts"] ?? false,
                $budget,
            )
            : $this->publicationSource($template, new EntityId($choice["version_id"]), $budget);
            if (
                $resolved["program_id"] !== $degree["program_id"] ||
                $resolved["academic_year_id"] !== $degree["academic_year_id"] ||
                ($resolved["specialization"]["id"] ?? null) !== $specialization
            ) {
                throw new CorruptHistoryException();
            }
            $sources[] = $resolved;
        }
        return $sources;
    }

    /**
     * Hypothetical sources retain their real template identities; no publication identity is fabricated.
     * @param list<array<string,mixed>> $sources
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function combinedReview(
        #[\SensitiveParameter] \Domain\DegreeAudit\ValueObject\AcademicInputSet $inputs,
        array $sources,
        ComponentCourseSharing $sharing,
        HistoryReadBudget $budget,
        ?string $pursuitStartedOn = null,
    ): array {
        $components = [];
        foreach ($sources as $index => $source) {
            $r = $source["requirements"] + [
                "version_id" => $source["version_id"],
                "version" => $source["version"],
                "context" => ["academic_year_code" => $source["academic_year_code"]],
                "specialization" => $source["specialization"],
            ];
            $components[] = [
                "key" => $source["template_id"],
                "kind" => $index === 0 ? "degree" : "specialization",
                "requirements" => $r,
                "effective" => DegreeProgressEvaluator::unadjusted($r, $pursuitStartedOn),
            ];
        }
        $result = $this->combined->evaluate($inputs, $components, $sharing, $budget);
        if (isset($result["components"])) {
            foreach ($result["components"] as $index => &$component) {
                $source = $sources[$index];
                $component["progress"]["context"]["test_source"] = $source["source"];
                $component['progress'] = self::sourceReview($component['progress'], $source);
            }
            unset($component);
        }
        return $result + [
            "mode" => "runtime_combined_test",
            "exceptions_applied" => false,
            "sharing_source" => "explicit_hypothesis",
        ];
    }

    /**
     * @return array{array<string,array<string,mixed>>,list<array<string,mixed>>}
     * @throws JsonException
     */
    private function groupInputs(AuditRequirementsV3 $definition, bool $drafts, HistoryReadBudget $budget): array
    {
        $references = [];
        foreach ($definition->definition["groups"] as $reference) {
            if (!$drafts && $reference["version_id"] !== null) {
                $references[] = $reference;
            }
        }
        $selected = $this->published->groupVersions($references, $budget);
        $groups = [];
        $sources = [];
        $courses = [];
        foreach ($definition->definition["groups"] as $reference) {
            $id = $reference["group_id"];
            if (isset($selected[$id])) {
                $groups[$id] = $selected[$id];
                $sources[] = [
                    "group_id" => $id,
                    "name" => $selected[$id]["definition"]["name"],
                    "source" => "publication",
                    "revision" => $selected[$id]["version"],
                ];
                continue;
            }
            $row = $this->groups->get(new EntityId($id));
            $d = CourseGroupDefinitionV2::draft($row["definition"]);
            $d->assertPublishable();
            $details = [];
            foreach ($d->definition["courses"] as $course) {
                $ids = [$course["version_id"], ...$course["accepted_version_ids"] ?? []];
                $missing = array_values(
                    array_filter($ids, static fn(string $v): bool => !isset($courses[$course["course_id"] . ":" . $v])),
                );
                if (count($courses) + count($missing) > 2000) {
                    throw new HistoryReadLimitException();
                }
                if ($missing !== []) {
                    $selectedVersions = $this->courses->selectedVersions(
                        new EntityId($course["course_id"]),
                        array_map(static fn(string $v): EntityId => new EntityId($v), $missing),
                        $budget,
                    );
                    foreach ($selectedVersions as $version => $selectedVersion) {
                        $published = $selectedVersion["publication"]->definition;
                        $courses[$course["course_id"] . ":" . $version] = [
                            "version_id" => $version,
                            "version" => $selectedVersion["version"],
                            "code" => $published->code,
                            "title" => $published->text->transcriptTitle ?? $published->title,
                            "credits_milli" => $published->creditsMilli,
                        ];
                    }
                }
                $details[$course["course_id"]] = $courses[$course["course_id"] . ":" . $course["version_id"]];
                if (($course["accepted_version_ids"] ?? []) !== []) {
                    $details[$course["course_id"]]["accepted_versions"] = array_map(
                        static fn(string $v): array => $courses[$course["course_id"] . ":" . $v],
                        $course["accepted_version_ids"],
                    );
                }
            }
            $groups[$id] = ["definition" => $d->definition, "version_id" => null, "course_details" => $details];
            $sources[] = [
                "group_id" => $id,
                "name" => $d->definition["name"],
                "source" => "draft",
                "revision" => $row["revision"],
            ];
        }
        return [$groups, $sources];
    }

    private function authorize(EntityId $student, EntityId $assignment, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->access->requirePermission("sis:student:read");
        $this->requirements->authorizeRead($student, $assignment);
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException("The signed-in account changed. Reload before testing.");
        }
        return $actor;
    }
}
