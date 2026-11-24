<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentDecisionProjectionVerifier;
use Domain\Student\Service\LoadDeterminationLedger;
use Domain\Student\Service\StudentStandingLedger;
use Domain\Student\ValueObject\StudentDecisionProjectionCheck;
use Domain\Advising\Service\AdvisorHistoryReader;
use Domain\DegreeAudit\Service\StudentRequirementsReader;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Selected retained decisions only; no write locks, policy recalculation, repairs or side effects. */
final readonly class SqlStudentDecisionProjectionVerifier implements StudentDecisionProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private StudentStandingLedger $standings,
        private LoadDeterminationLedger $loads,
        private AdvisorHistoryReader $advisors,
        private StudentRequirementsReader $requirements,
        private PublishedRequirementsReader $publications,
    ) {
    }

    public function standing(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck
    {
        return $this->verify('student_standing', $pursuit, $eventLimit);
    }

    public function load(EntityId $determination, int $eventLimit = 500): StudentDecisionProjectionCheck
    {
        return $this->verify('enrollment_load_determination', $determination, $eventLimit);
    }

    public function advisors(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck
    {
        return $this->verify('student_advisors', $pursuit, $eventLimit);
    }

    public function requirements(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck
    {
        return $this->verify('student_degree_requirements', $pursuit, $eventLimit);
    }

    private function verify(string $scope, EntityId $id, int $eventLimit): StudentDecisionProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use a student-decision verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new StudentDecisionProjectionCheck('unverified', $scope, $id, 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $status = 'unverified';
        $issue = match ($scope) {
            'student_standing' => 'standing',
            'student_advisors' => 'advisors',
            'student_degree_requirements' => 'requirements',
            default => 'load_decision',
        };
        $issues = [];
        $position = $records = null;
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($scope, $id);
            if (
                count($before['pursuit']) !== 1 || count($before['student']) !== 1
                || count($before['person']) !== 1
                || ($scope === 'enrollment_load_determination'
                    && ($before['projection'][0]['student_id'] ?? null) !== $before['student'][0]['id'])
            ) {
                $issues = [$scope !== 'enrollment_load_determination' ? 'pursuit_identity' : 'load_decision'];
            } else {
                $heads = $before['head'];
                $position = $heads === [] ? 0 : $this->position($heads);
                if ($position > $eventLimit) {
                    throw new HistoryReadLimitException();
                }
                if ($scope === 'student_standing') {
                    $root = $this->standings->load(new EntityId($before['student'][0]['id']), $id, $budget);
                    if ($root->playhead() !== $position) {
                        throw new CorruptHistoryException();
                    }
                    $records = count($root->rows());
                    if ($records !== count($before['projection'])) {
                        throw new CorruptHistoryException();
                    }
                    $status = $position === 0 ? 'observed_empty' : 'verified';
                } elseif ($scope === 'student_advisors') {
                    $root = $this->advisors->read(new EntityId($before['student'][0]['id']), $id, $budget);
                    if ($root->playhead() !== $position) {
                        throw new CorruptHistoryException();
                    }
                    $records = count($root->rows());
                    $status = $position === 0 ? 'observed_empty' : 'verified';
                } elseif ($scope === 'student_degree_requirements') {
                    $root = $this->requirements->read($id, $budget);
                    if ($root->playhead() !== $position) {
                        throw new CorruptHistoryException();
                    }
                    $selected = $root->selection();
                    if ($selected !== null) {
                        if (
                            $selected['student_id'] !== $before['student'][0]['id'] ||
                            $selected['program_id'] !== $before['pursuit'][0]['program_id']
                        ) {
                            throw new CorruptHistoryException();
                        }
                        $published = $this->publications->version(
                            new EntityId($selected['template_id']),
                            new EntityId($selected['requirements_version_id']),
                            $budget,
                        );
                        if (
                            ($published['specialization_id'] ?? null) !== null ||
                            $published['program_id'] !== $selected['program_id'] ||
                            $published['academic_year_id'] !== $selected['academic_year_id'] ||
                            (($published['program_version_id'] ?? null) !== null &&
                                $published['program_version_id'] !== $selected['cohort_catalog_id'])
                        ) {
                            throw new CorruptHistoryException();
                        }
                    }
                    $records = $selected === null ? 0 : 1;
                    $status = $position === 0 ? 'observed_empty' : 'verified';
                } else {
                    $stored = $this->loads->restore($id, $budget);
                    if ($stored === null || $position !== 1) {
                        throw new CorruptHistoryException();
                    }
                    $records = 1;
                    $status = 'verified';
                }
                $budget->checkTime();
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException) {
            $status = 'unverified';
            $issues = [$issue];
        } catch (Throwable) {
            $status = 'unverified';
            $issues = ['unavailable'];
        }
        if ($before !== null) {
            try {
                // Compare routing, projection and head outside a snapshot, including after a failed read.
                if ($before !== $this->boundary($scope, $id)) {
                    $status = 'changed';
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== 'changed') {
                    $status = 'limited';
                    $issues = [];
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        return new StudentDecisionProjectionCheck(
            $status,
            $scope,
            $id,
            $budget->eventsRead(),
            $position,
            in_array($status, ['verified', 'observed_empty'], true) ? $records : null,
            $issues,
        );
    }

    private function position(array $heads): int
    {
        $value = $heads[0]['version'];
        if (
            count($heads) !== 1 || !is_scalar($value)
            || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)
            || (int) $value > 2000000000 || (string) (int) $value !== (string) $value
        ) {
            throw new CorruptHistoryException();
        }
        return (int) $value;
    }

    private function boundary(string $scope, EntityId $id): array
    {
        $q = $this->sql->quote(...);
        $load = $scope === 'enrollment_load_determination';
        $projection = $load ? $this->sql->rows('sis_load_determinations', ['id' => $id->value], 2) : [];
        $pursuit = $load ? ($projection[0]['pursuit_id'] ?? null) : $id->value;
        $assignments = $pursuit === null ? [] : $this->sql->rows('sis_student_programs', ['id' => $pursuit], 2);
        $student = $assignments[0]['student_id'] ?? null;
        $students = $student === null ? [] : $this->sql->rows('sis_students', ['id' => $student], 2);
        $person = $students[0]['person_id'] ?? null;
        if (!$load) {
            // Include wrong-student rows under this pursuit so they cannot disappear from the observation.
            [$table, $column] = match ($scope) {
                'student_advisors' => ['sis_student_advisors', 'pursuit_id'],
                'student_degree_requirements' => ['sis_student_requirements', 'id'],
                default => ['sis_student_standing', 'pursuit_id'],
            };
            $projection = $this->sql->fetch('SELECT * FROM ' . $q($table)
                . ' WHERE ' . $q($column) . ' = ? ORDER BY ' . $q('id'), [$id->value], 51);
        }
        return [
            'projection' => $projection,
            'pursuit' => $assignments,
            'student' => $students,
            'person' => $person === null ? [] : $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'owner_user_id'])) . ' FROM ' . $q('sis_persons')
                . ' WHERE ' . $q('id') . ' = ?', [$person], 2),
            'head' => $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
                . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
                . $q('aggregate_id') . ' = ?', [$scope, $id->value], 2),
        ];
    }
}
