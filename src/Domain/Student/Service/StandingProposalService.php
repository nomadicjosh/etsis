<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\GradingRepublicationVerifier;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentStandingRepository;
use Domain\Student\ValueObject\StandingProposalEvidence;
use Domain\Student\ValueObject\StandingProposalRules;

use function Codefy\Framework\Helpers\trans_html;

/** Internal reader; the standing coordinator authorizes and locks the current receiving pursuit. */
final readonly class StandingProposalService
{
    public function __construct(
        private StandingProposalPolicyService $policies,
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private GradingRepublicationVerifier $grading,
        private RetainedAttemptReader $attempts,
        private AcademicPolicyEvaluator $evaluator,
        private StudentStandingRepository $definitions,
        private Clock $clock,
        private StudentProgramStatusLedger $programs,
        private \Domain\Student\Repository\StudentRecordRepository $students,
        private \Domain\Catalog\Service\PublishedProgramReader $publications,
    ) {
    }

    public function calculate(EntityId $student, EntityId $pursuit, EntityId $period, HistoryReadBudget $budget): array
    {
        $publication = $this->policies->current($budget);
        $rules = new StandingProposalRules($publication['rules']);
        if ($publication['publication_id'] === null) {
            $this->blocked(trans_html('Publish an enabled standing proposal policy before calculating a proposal.'));
        }
        $context = $this->requirements->calculationContext($student, $pursuit, $budget);
        $assignment = $this->students->assignment($pursuit);
        $cohort = $this->programs->cohortContext($assignment, $budget);
        $levelSource = null;
        if ($rules->programOverride($assignment->programId->value) === null && $rules->hasLevelOverrides()) {
            if ($cohort['catalog'] === null || !$cohort['source_recorded']) {
                $this->blocked(trans_html(
                    'Assign a verified published program cohort before applying academic-level standing rules.'
                ));
            }
            $catalog = $cohort['catalog'];
            $program = $this->publications->version(
                $assignment->programId,
                new EntityId($catalog['year_id']),
                new EntityId($catalog['catalog_id']),
                $budget
            );
            if ($program === null || !array_key_exists('academic_level', $program['definition']['classifications'])) {
                $this->blocked(trans_html('The assigned program cohort needs verified academic-level information.'));
            }
            $levelSource = ['academic_year_id' => $catalog['year_id'], 'program_version_id' => $catalog['catalog_id'],
                'academic_level' => $program['definition']['classifications']['academic_level']];
        }
        $scope = new \Domain\Student\ValueObject\StandingProposalScope([
            'program_id' => $assignment->programId->value, 'pursuit_version' => $cohort['stream_version'],
            'level_source' => $levelSource,
        ]);
        $selection = $rules->selection($scope);
        $effective = new StandingProposalRules($selection['rules']);
        if (!$effective->facts['enabled']) {
            $this->blocked(trans_html('Standing proposals are disabled by the policy that applies to this pursuit.'));
        }
        $inputs = $this->inputs->collect($student, $pursuit, $budget);
        if ($inputs->status === AcademicInputStatus::Limited) {
            throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
        }
        if ($inputs->status === AcademicInputStatus::Inconsistent) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if ($context === null || $inputs->status !== AcademicInputStatus::Verified) {
            $this->blocked(trans_html(
                'Select retained requirements and verify this pursuit’s academic work before proposing standing.'
            ));
        }
        $requirements = $this->grading->prepare($inputs->items, $context['requirements'], $budget);
        $calculation = AuditCalculationPolicy::fromRequirements($requirements);
        $attempts = $this->attempts->forPolicy($inputs, $calculation, $budget);
        $result = $this->evaluator->evaluate($attempts, $calculation, AcademicGpaScope::Institutional);
        if ($result->gpa?->milli() === null) {
            $this->blocked(trans_html(
                'A verified institutional GPA is required; undefined GPA does not mean zero GPA.'
            ));
        }
        if ($result->gpa->creditsMilli < $effective->facts['minimum_gpa_credits_milli']) {
            $this->blocked(trans_html('This pursuit has not reached the policy’s minimum GPA credits.'));
        }
        $band = $effective->band($result->gpa->milli());
        $definition = $this->definitions->definition(new EntityId($band['definition_id']));
        if (
            !$definition['active'] || $definition['code'] !== $band['code']
            || $definition['title'] !== $band['title']
        ) {
            $this->blocked(trans_html(
                'The proposed standing definition changed or was retired. Review the published GPA policy.'
            ));
        }
        $frozen = array_map(static fn($a): array => [
            'id' => $a->id->value, 'course_id' => $a->courseId->value, 'credit_type' => $a->origin,
            'status' => $a->status, 'attempted_milli' => $a->attemptedMilli, 'completed_milli' => $a->earnedMilli,
            'quality_points_milli' => $a->qualityMilli, 'grade_recorded' => $a->gradeRecorded,
            'grade' => $a->grade?->facts, 'completed_on' => $a->completedOn, 'academic_on' => $a->academicOn,
        ], $attempts);
        $evidence = [
            'student_id' => $student->value, 'pursuit_id' => $pursuit->value, 'period_id' => $period->value,
            'policy_publication_id' => $publication['publication_id'], 'policy_version' => $publication['version'],
            'rules' => $rules->facts, 'scope' => $scope->data, 'requirements_version_id' => $requirements['version_id'],
            'context_revision' => StandingProposalRules::hash([$context, $cohort]),
            'input_revision' => StandingProposalRules::hash([$inputs->items, $inputs->references]),
            'as_of' => $this->clock->now()->format('Y-m-d'),
            'gpa_credits_milli' => $result->gpa->creditsMilli, 'quality_points_milli' => $result->gpa->qualityMilli,
            'calculation' => ['definition' => ['policies' => $requirements['definition']['policies'] ?? []],
                'grade_scale' => ['version_id' => $requirements['grade_scale']['version_id'] ?? null],
                'identical_grading_publications' => $requirements['identical_grading_publications'] ?? []],
            'attempts' => $frozen,
            'source_positions' => array_map(static fn(array $r): array => array_intersect_key($r, array_flip([
                'credit_id', 'source_id', 'section_id', 'academic_version', 'registration_version', 'pursuit_version',
            ])), $inputs->references),
        ];
        new StandingProposalEvidence($evidence);
        return $evidence;
    }

    public function summary(#[\SensitiveParameter] StandingProposalEvidence $proof, HistoryReadBudget $budget): array
    {
        $this->policies->verifyEvidence($proof, $budget);
        $gpa = new \Domain\DegreeAudit\ValueObject\GpaTotals(
            $proof->data['quality_points_milli'],
            $proof->data['gpa_credits_milli']
        );
        return ['policy_version' => $proof->data['policy_version'], 'gpa_milli' => $gpa->milli(),
            'gpa_credits_milli' => $gpa->creditsMilli, 'as_of' => $proof->data['as_of'],
            ...$proof->scopeSummary() === null ? [] : ['scope' => $proof->scopeSummary()]];
    }

    private function blocked(string $message): never
    {
        throw new ValidationException([['field' => 'standing_proposal', 'message' => $message]]);
    }
}
