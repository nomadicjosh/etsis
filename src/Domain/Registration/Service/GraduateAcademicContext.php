<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Advising\Service\AdvisorHistoryReader;
use Domain\Advising\ValueObject\AdvisorAssignments;
use Domain\Catalog\Course;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Catalog\Service\PublishedProgramReader;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\GradingRepublicationVerifier;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramStatusLedger;
use Domain\Student\StudentProgram;

use function Codefy\Framework\Helpers\trans;

/** Internal eligibility reader. Callers authorize and hold the student/person/section/course boundary. */
final readonly class GraduateAcademicContext
{
    public function __construct(
        private GraduateRegistrationPolicyService $policies,
        private PublishedCourseReader $courses,
        private PublishedProgramReader $programs,
        private StudentProgramStatusLedger $pursuits,
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private GradingRepublicationVerifier $grading,
        private RetainedAttemptReader $attempts,
        private AcademicPolicyEvaluator $evaluator,
        private AdvisorHistoryReader $advisors,
        private Clock $clock,
        private \Domain\Admissions\Repository\AdmissionsRepository $admissions,
    ) {
    }

    public function read(
        StudentProgram $pursuit,
        Section $section,
        Course $course,
        HistoryReadBudget $budget,
        ?RegistrationCourseRead $read = null,
    ): ?GraduateCourseScope {
        $publication = $this->policies->current($budget);
        $rules = $publication["rules"];
        if (!$rules["enabled"]) {
            return null;
        }
        $definition = ($read ?? new RegistrationCourseRead())->definition($course, $this->courses, $budget);
        $level = $definition?->classifications->academicLevel?->id->value;
        if ($level === null) {
            throw new \DomainException(
                trans(
                    "Publish an academic level for this course before checking graduate-course eligibility.",
                ),
            );
        }
        if ($level !== $rules["graduate_level_id"]) {
            return null;
        }
        $cohort = $this->pursuits->cohortContext($pursuit, $budget);
        $catalog = $cohort["catalog"];
        if ($catalog === null || !$cohort["source_recorded"]) {
            throw new \DomainException(
                trans("Assign a published program cohort before checking graduate-course eligibility."),
            );
        }
        $program = $this->programs->version(
            $pursuit->programId,
            new EntityId($catalog["year_id"]),
            new EntityId($catalog["catalog_id"]),
            $budget,
        );
        $programLevel = $program["definition"]["classifications"]["academic_level"]["id"] ?? null;
        if ($programLevel === null) {
            throw new \DomainException(
                trans("Publish an academic level for the student’s assigned program cohort."),
            );
        }
        if ($programLevel !== $rules["undergraduate_level_id"]) {
            return null;
        }
        if ($section->publicationVersionId === null) {
            throw new \DomainException(
                trans("Select a published section offering before requesting graduate-course approval."),
            );
        }
        $context = $this->requirements->calculationContext($pursuit->studentId, $pursuit->id, $budget);
        $inputs = $this->inputs->collect($pursuit->studentId, $pursuit->id, $budget);
        if ($context === null || $inputs->status !== AcademicInputStatus::Verified) {
            throw new \DomainException(
                trans(
                    "Verified current-pursuit academic work and retained calculation policies are required.",
                ),
            );
        }
        $requirements = $this->grading->prepare($inputs->items, $context["requirements"], $budget);
        $calculation = AuditCalculationPolicy::fromRequirements($requirements);
        $attempts = $this->attempts->forPolicy($inputs, $calculation, $budget);
        $result = $this->evaluator->evaluate($attempts, $calculation, AcademicGpaScope::Institutional);
        if ($result->gpa?->meetsMinimum($rules["minimum_gpa_milli"]) !== true) {
            throw new \DomainException(
                trans(
                    "The current undergraduate pursuit GPA must meet the institutional minimum at registration.",
                ),
            );
        }
        // A new in-progress registration does not change the completed-work source revision.
        $graded = array_values(
            array_filter(
                $inputs->items,
                static fn(array $w): bool => $w["credit_type"] !== "transfer" &&
                    !in_array($w["status"], ["D", "V"], true) &&
                    ($w["grade_recorded"] ||
                        $w["grade"] !== null ||
                        $w["completed_milli"] !== 0 ||
                        $w["quality_points_milli"] !== 0),
            ),
        );
        usort($graded, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        $gradedIds = array_fill_keys(array_column($graded, "id"), true);
        $gpaSources = [];
        foreach ($inputs->references as $reference) {
            if (isset($gradedIds[$reference["credit_id"]])) {
                $gpaSources[] = array_intersect_key(
                    $reference,
                    array_flip([
                        "credit_id",
                        "source_id",
                        "section_id",
                        "academic_version",
                        "registration_version",
                        "pursuit_version",
                    ]),
                );
            }
        }
        usort($gpaSources, static fn(array $a, array $b): int => strcmp($a["credit_id"], $b["credit_id"]));
        $grades = [];
        $definitions = [];
        if ($rules["minimum_graduate_grade_milli"] !== null) {
            foreach ($graded as $work) {
                if ($work["status"] === "W") {
                    continue;
                }
                $courseId = $work["registration_course_id"] ?? $work["course_id"];
                $versionId = $work["registration_course_version_id"] ?? $work["course_version_id"];
                if ($versionId === null) {
                    throw new \DomainException(
                        trans(
                            "A completed course needs a verified academic level before continued graduate enrollment.",
                        ),
                    );
                }
                $key = $courseId . ":" . $versionId;
                $definitions[$key] ??= $this->courses->version(
                    new EntityId($courseId),
                    new EntityId($versionId),
                    $budget,
                )->definition;
                $completedLevel = $definitions[$key]->classifications->academicLevel?->id->value;
                if ($completedLevel === null) {
                    throw new \DomainException(
                        trans(
                            "A completed course needs a verified academic level before continued graduate enrollment.",
                        ),
                    );
                }
                if ($completedLevel !== $rules["graduate_level_id"]) {
                    continue;
                }
                $grade = $work["grade"];
                if (
                    $grade === null ||
                    !$grade["counts_gpa"] ||
                    $grade["scale_version_id"] === null ||
                    !$calculation->acceptsGradingPublication($grade["scale_version_id"]) ||
                    $grade["points_milli"] < $rules["minimum_graduate_grade_milli"]
                ) {
                    throw new \DomainException(
                        trans(
                            "Every completed graduate course in this pursuit must meet the grade minimum.",
                        ),
                    );
                }
                $grades[] = [
                    "credit_id" => $work["id"],
                    "course_id" => $courseId,
                    "course_version_id" => $versionId,
                    "grade_id" => $grade["id"],
                    "grade_points_milli" => $grade["points_milli"],
                    "source_position" => $work["history_version"],
                ];
            }
        }
        $advisor = $this->advisors->read($pursuit->studentId, $pursuit->id, $budget);
        $current = new AdvisorAssignments($advisor->rows())->on($this->clock->now()->format("Y-m-d"));
        return new GraduateCourseScope([
            "student_id" => $pursuit->studentId->value,
            "person_id" => $this->admissions->student($pursuit->studentId)->personId->value,
            "pursuit_id" => $pursuit->id->value,
            "program_id" => $pursuit->programId->value,
            "program_version_id" => $catalog["catalog_id"],
            "academic_year_id" => $catalog["year_id"],
            "section_id" => $section->id->value,
            "section_version_id" => $section->publicationVersionId->value,
            "course_id" => $course->id->value,
            "course_version_id" => $course->publicationVersionId->value,
            "policy_publication_id" => $publication["publication_id"],
            "undergraduate_level_id" => $rules["undergraduate_level_id"],
            "graduate_level_id" => $rules["graduate_level_id"],
            "policy_version" => $publication["version"],
            "advisor_revision" => GraduateRegistrationRules::hash([$advisor->playhead(), $current]),
            "gpa" => [
                "quality_milli" => $result->gpa->qualityMilli,
                "credits_milli" => $result->gpa->creditsMilli,
            ],
            "gpa_source_hash" => GraduateRegistrationRules::hash([$context, $graded, $gpaSources]),
            "gpa_sources" => $gpaSources,
            "calculation_reference" => array_intersect_key(
                $requirements,
                array_flip(["template_id", "version_id", "version"]),
            ),
            "minimum_gpa_milli" => $rules["minimum_gpa_milli"],
            "minimum_graduate_grade_milli" => $rules["minimum_graduate_grade_milli"],
            "graduate_grade_source_hash" => GraduateRegistrationRules::hash($grades),
            "graduate_grades" => $grades,
        ]);
    }
}
