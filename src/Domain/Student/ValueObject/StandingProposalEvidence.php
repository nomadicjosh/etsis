<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Private frozen calculation; the ordinary standing projection contains only accepted periods. */
final readonly class StandingProposalEvidence implements \JsonSerializable
{
    private const array FIELDS = ['student_id', 'pursuit_id', 'period_id', 'policy_publication_id',
        'policy_version', 'rules', 'requirements_version_id', 'context_revision', 'input_revision', 'as_of',
        'gpa_credits_milli', 'quality_points_milli', 'calculation', 'attempts', 'source_positions'];

    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            if (
                count($data) < count(self::FIELDS) || count($data) > count(self::FIELDS) + 3
                || array_diff(array_keys($data), [...self::FIELDS, 'scope', 'assessment', 'load_assessment']) !== []
                || array_diff(self::FIELDS, array_keys($data)) !== []
                || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 1_048_576
                || !is_int($data['policy_version']) || $data['policy_version'] < 1 || $data['policy_version'] > 2000
                || !is_array($data['attempts']) || !array_is_list($data['attempts']) || count($data['attempts']) > 500
                || !is_array($data['source_positions']) || !array_is_list($data['source_positions'])
                || count($data['source_positions']) > 1000
            ) {
                throw new \InvalidArgumentException();
            }
            $identities = ['student_id', 'pursuit_id', 'period_id', 'policy_publication_id', 'requirements_version_id'];
            foreach ($identities as $key) {
                if (!is_string($data[$key]) || new EntityId($data[$key])->value !== $data[$key]) {
                    throw new \InvalidArgumentException();
                }
            }
            foreach (['context_revision', 'input_revision'] as $key) {
                if (!is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            StandingPeriods::date($data['as_of']);
            $rules = $this->effectiveRules();
            $assessment = array_key_exists('assessment', $data) ? new StandingGpaAssessment($data['assessment']) : null;
            if (isset($rules->facts['load_requirement']) !== array_key_exists('load_assessment', $data)) {
                throw new \InvalidArgumentException();
            }
            if (array_key_exists('load_assessment', $data)) {
                new StandingLoadAssessment($data['load_assessment'])->validateFor(
                    $data['student_id'],
                    $data['pursuit_id'],
                    $data['as_of'],
                    $assessment?->data['term']['id'] ?? null,
                    $rules->facts['load_requirement']
                );
            }
            if (
                ($assessment === null && ($rules->gpaBasis() !== 'cumulative'
                    || array_key_exists('gpa_basis', $rules->facts)))
                || ($assessment !== null && $assessment->data['basis'] !== $rules->gpaBasis())
            ) {
                throw new \InvalidArgumentException();
            }
            if (
                !is_array($data['calculation']) || count($data['calculation']) !== 3
                || array_diff(
                    array_keys($data['calculation']),
                    ['definition', 'grade_scale', 'identical_grading_publications']
                ) !== []
                || array_keys($data['calculation']['definition']) !== ['policies']
                || array_keys($data['calculation']['grade_scale']) !== ['version_id']
            ) {
                throw new \InvalidArgumentException();
            }
            $policy = AuditCalculationPolicy::fromRequirements($data['calculation']);
            $attempts = [];
            foreach ($data['attempts'] as $work) {
                $fields = ['id', 'course_id', 'credit_type', 'status', 'attempted_milli', 'completed_milli',
                    'quality_points_milli', 'grade_recorded', 'grade', 'completed_on', 'academic_on'];
                if (($assessment?->data['basis'] ?? 'cumulative') === 'term') {
                    $fields[] = 'posting_term_id';
                    if (
                        ($work['credit_type'] ?? null) !== 'institutional'
                        || ($work['posting_term_id'] ?? null) !== $assessment->data['term']['id']
                    ) {
                        throw new \InvalidArgumentException();
                    }
                }
                if (
                    !is_array($work) || count($work) !== count($fields)
                    || array_diff(array_keys($work), $fields) !== [] || isset($attempts[$work['id']])
                ) {
                    throw new \InvalidArgumentException();
                }
                $attempts[$work['id']] = PolicyAttempt::fromAcademicWork($work, $work['academic_on']);
            }
            $sources = [];
            foreach ($data['source_positions'] as $reference) {
                if (
                    !is_array($reference) || !isset($reference['credit_id'])
                    || isset($sources[$reference['credit_id']])
                    || array_diff(array_keys($reference), ['credit_id', 'source_id', 'section_id',
                        'academic_version', 'registration_version', 'pursuit_version']) !== []
                ) {
                    throw new \InvalidArgumentException();
                }
                $sources[$reference['credit_id']] = true;
                foreach ($reference as $key => $value) {
                    if (str_ends_with($key, '_id')) {
                        if ($value !== null && (!is_string($value) || new EntityId($value)->value !== $value)) {
                            throw new \InvalidArgumentException();
                        }
                    } elseif ($value !== null && (!is_int($value) || $value < 0)) {
                        throw new \InvalidArgumentException();
                    }
                }
            }
            if (array_diff(array_keys($attempts), array_keys($sources)) !== []) {
                throw new \InvalidArgumentException();
            }
            $result = new AcademicPolicyEvaluator()->evaluate(
                array_values($attempts),
                $policy,
                AcademicGpaScope::Institutional
            );
            if (
                !$rules->facts['enabled'] || $result->gpa?->milli() === null
                || $result->gpa->creditsMilli !== $data['gpa_credits_milli']
                || $result->gpa->qualityMilli !== $data['quality_points_milli']
                || $result->gpa->creditsMilli < $rules->facts['minimum_gpa_credits_milli']
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function band(): array
    {
        $gpa = new \Domain\DegreeAudit\ValueObject\GpaTotals(
            $this->data['quality_points_milli'],
            $this->data['gpa_credits_milli']
        );
        return $this->effectiveRules()->band($gpa->milli());
    }

    private function effectiveRules(): StandingProposalRules
    {
        $rules = new StandingProposalRules($this->data['rules']);
        if (!array_key_exists('scope', $this->data)) {
            if (array_key_exists('overrides', $rules->facts)) {
                throw new CorruptHistoryException();
            }
            return $rules;
        }
        $scope = new StandingProposalScope($this->data['scope']);
        return new StandingProposalRules($rules->selection($scope)['rules']);
    }

    public function assessmentSummary(): array
    {
        return isset($this->data['assessment']) ? new StandingGpaAssessment($this->data['assessment'])->summary()
        : ['basis' => 'cumulative'];
    }

    public function scopeSummary(): ?array
    {
        if (!array_key_exists('scope', $this->data)) {
            return null;
        }
        $scope = new StandingProposalScope($this->data['scope']);
        $selection = new StandingProposalRules($this->data['rules'])->selection($scope);
        return ['kind' => $selection['kind'], ...$selection['target'] === null ? []
            : array_intersect_key($selection['target'], array_flip(['code', 'title']))];
    }

    public function routing(string $student, string $pursuit, #[\SensitiveParameter] array $rows): void
    {
        $row = array_find($rows, fn(array $r): bool => $r['id'] === $this->data['period_id']);
        $band = $this->band();
        if (
            $this->data['student_id'] !== $student || $this->data['pursuit_id'] !== $pursuit || $row === null
            || $row['definition_id'] !== $band['definition_id'] || $row['code'] !== $band['code']
            || $row['title'] !== $band['title']
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['standing_proposal' => '[protected institutional GPA calculation]'];
    }
}
