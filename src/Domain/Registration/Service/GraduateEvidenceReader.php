<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Catalog\Service\PublishedProgramReader;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateEnrollmentEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Authenticate the accepted policy and both original decisions. Never substitute today's GPA or approval state. */
final readonly class GraduateEvidenceReader
{
    public function __construct(
        private GraduateRegistrationPolicyService $policies,
        private GraduateApprovalLedger $approvals,
        private PublishedCourseReader $courses,
        private PublishedProgramReader $programs,
        private \Domain\DegreeAudit\Service\PublishedRequirementsReader $requirements,
    ) {
    }

    public function verify(GraduateEnrollmentEvidence $evidence, HistoryReadBudget $budget): void
    {
        $scope = $evidence->scope;
        $s = $scope->data;
        $this->verifyScope($scope, $budget);
        $root = $this->approvals->read(new EntityId($s["pursuit_id"]), $budget);
        $advisor = $root->record(new EntityId($evidence->data["advisor_operation_id"]));
        $registrar = $root->record(new EntityId($evidence->data["registrar_operation_id"]));
        if (
            $advisor === null ||
            $registrar === null ||
            $advisor["action"] !== "advisor_approve" ||
            $registrar["action"] !== "registrar_override" ||
            $registrar["advisor_operation_id"] !== $advisor["operation_id"] ||
            $advisor["position"] >= $registrar["position"] ||
            $registrar["position"] > $evidence->data["approval_version"] ||
            new GraduateCourseScope($advisor["scope"])->binding() !== $scope->binding() ||
            new GraduateCourseScope($registrar["scope"])->binding() !== $scope->binding()
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** Authenticate only retained policy/catalog references; this never recalculates a historical GPA. */
    public function verifyScope(GraduateCourseScope $scope, HistoryReadBudget $budget): PublishedCourseDefinition
    {
        $s = $scope->data;
        $policy = $this->policies->recorded(new EntityId($s["policy_publication_id"]), $budget);
        if ($policy["version"] !== $s["policy_version"] || !$policy["rules"]["enabled"]) {
            throw new CorruptHistoryException();
        }
        $fields = [
            "undergraduate_level_id",
            "graduate_level_id",
            "minimum_gpa_milli",
            "minimum_graduate_grade_milli",
        ];
        foreach ($fields as $key) {
            if ($policy["rules"][$key] !== $s[$key]) {
                throw new CorruptHistoryException();
            }
        }
        $calculation = $s["calculation_reference"];
        $retained = $this->requirements->calculationVersion(
            new EntityId($calculation["template_id"]),
            new EntityId($calculation["version_id"]),
            $budget,
        );
        if (
            $retained["version"] !== $calculation["version"] ||
            $retained["program_id"] !== $s["program_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $course = $this->courses->version(
            new EntityId($s["course_id"]),
            new EntityId($s["course_version_id"]),
            $budget,
        );
        $program = $this->programs->version(
            new EntityId($s["program_id"]),
            new EntityId($s["academic_year_id"]),
            new EntityId($s["program_version_id"]),
            $budget,
        );
        if (
            $course->definition->classifications->academicLevel?->id->value !== $s["graduate_level_id"] ||
            ($program["definition"]["classifications"]["academic_level"]["id"] ?? null) !==
                $s["undergraduate_level_id"]
        ) {
            throw new CorruptHistoryException();
        }
        return $course;
    }
}
