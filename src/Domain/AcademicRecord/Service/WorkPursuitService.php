<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use Domain\AcademicRecord\Exception\WorkPursuitRequiredException;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentPrimaryProgramLedger;
use Domain\Student\Service\StudentProgramStatusLedger;
use JsonException;

/** Authoritative pursuit applicability, separate from grade-policy context and curriculum publication. */
final readonly class WorkPursuitService
{
    public const string PERMISSION = 'sis:academic-work:scope';

    public function __construct(
        private WorkPursuitLedger $ledger,
        private AcademicInputVerifier $inputs,
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentPrimaryProgramLedger $primary,
        private StudentProgramStatusLedger $statuses,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /** Internal creator selection, after the caller authorizes and locks the student.
     * Primary selection is a default for a new decision, never historical attribution evidence.
     */
    public function activePursuit(EntityId $student, ?EntityId $assignment = null): EntityId
    {
        $assignment ??= new EntityId($this->primary->current($this->admissions->student($student))['assignment_id']);
        $record = $this->students->assignment($assignment);
        $this->statuses->verify($record);
        if ($record->studentId->value !== $student->value || $record->status !== 'active') {
            self::invalid('Choose an active program pursuit belonging to this student.');
        }
        return $assignment;
    }

    /** Internal filter, under the authorized student lock and caller's shared replay allowance. */
    public function forPursuit(
        AcademicInputSet $inputs,
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
    ): AcademicInputSet {
        $byId = array_column($inputs->items, null, 'id');
        $items = $references = [];
        $gaps = false;
        foreach ($inputs->references as $reference) {
            $root = $this->ledger->load(new EntityId($reference['credit_id']), $budget);
            $scope = $root->projection();
            if ($scope === null) {
                if ($reference['removed']) {
                    continue;
                }
                throw new WorkPursuitRequiredException();
            }
            $expected = ['student_id' => $student->value, 'credit_id' => $reference['credit_id'],
                'origin' => $reference['origin'], 'source_id' => $reference['source_id']];
            if (WorkPursuitLedger::fingerprint($scope['source']) !== WorkPursuitLedger::fingerprint($expected)) {
                throw new CorruptHistoryException();
            }
            if (!$root->appliesTo($assignment->value)) {
                continue;
            }
            $references[] = $reference + ["pursuit_version" => $root->playhead()];
            if (!$reference['removed']) {
                $item = $byId[$reference['credit_id']] ?? throw new CorruptHistoryException();
                $items[] = $item;
                $gaps = $gaps || $reference['earlier_history_unknown'];
            }
        }
        return new AcademicInputSet(
            $gaps ? AcademicInputStatus::HistoryGaps : AcademicInputStatus::Verified,
            $items,
            $references
        );
    }

    /**
     * Per-record page check; the ordinary academic-work page must not expose another pursuit's candidates.
     * @param array<string,mixed> $credit
     * @throws JsonException
     */
    public function applies(array $credit, EntityId $assignment, HistoryReadBudget $budget): bool
    {
        $root = $this->ledger->load(new EntityId($credit['id']), $budget);
        $p = $root->projection() ?? throw new WorkPursuitRequiredException();
        if (
            WorkPursuitLedger::fingerprint($p['source']) !==
            WorkPursuitLedger::fingerprint(WorkPursuitLedger::source($credit))
        ) {
            throw new CorruptHistoryException();
        }
        return $root->appliesTo($assignment->value);
    }

    /**
     * Bounded office-only choices, including unknown or other-pursuit work. Never used by an audit.
     * @return array<string,mixed>
     */
    public function page(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $after, $actor): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $this->admissions->student($student);
            $budget = new HistoryReadBudget();
            $inputs = $this->inputs->verify($student, $budget);
            $refs = array_column($inputs->references, null, 'credit_id');
            $rows = array_values(array_filter(
                $inputs->items,
                static fn(array $work): bool => $after === null || strcmp($work['id'], $after->value) > 0
            ));
            usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
            $items = [];
            foreach (array_slice($rows, 0, 20) as $work) {
                $p = $this->ledger->load(new EntityId($work['id']), $budget)->projection();
                $ref = $refs[$work['id']];
                if (
                    $p !== null && $p['source'] != ['student_id' => $student->value,
                    'credit_id' => $work['id'], 'origin' => $ref['origin'], 'source_id' => $ref['source_id']]
                ) {
                    throw new CorruptHistoryException();
                }
                $owner = $p === null ? null : $this->target(
                    $student,
                    new EntityId($p['owner_assignment_id']),
                    'read',
                    $budget
                );
                $items[] = ['id' => $work['id'], 'course_code' => $work['course_code'],
                    'course_title' => $work['course_title'], 'credit_type' => $work['credit_type'],
                    'term_code' => $work['term_code'], 'owner' => $owner,
                    'reuse_assignment_ids' => $p === null ? [] : array_keys($p['reuse'])];
            }
            $this->authorize($actor);
            return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
        });
    }

    /** @return array<string,mixed> */
    public function pursuits(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $after, $actor): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $this->admissions->student($student);
            $rows = $this->students->assignments($student, $after, 21);
            $budget = new HistoryReadBudget();
            $items = [];
            foreach (array_slice($rows, 0, 20) as $row) {
                $items[] = $this->target($student, new EntityId($row['id']), 'read', $budget);
            }
            $this->authorize($actor);
            return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
        });
    }

    /** Independent office permission. Reviewing scope does not confer access through a faculty role. */
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        $this->access->requirePermission('sis:student:read');
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException('The academic-work reviewer changed.');
        }
        return $actor;
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $credit,
        EntityId $assignment,
        string $action,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = trim($reason);
        if (
            $reason === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8') ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            self::invalid('Explain the academic-work scope decision in at most 1,000 characters.');
        }
        return $this->transactions->run(function () use (
            $student,
            $credit,
            $assignment,
            $action,
            $reason,
            $actor
        ): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $budget = new HistoryReadBudget();
            $work = $this->work($student, $credit, $budget);
            $target = $this->target($student, $assignment, $action, $budget);
            $root = $this->ledger->load($credit, $budget);
            $scope = $root->projection();
            if (
                !in_array($action, ['attribute', 'correct_attribution', 'approve_reuse', 'revoke_reuse'], true) ||
                ($action === 'attribute' ? $scope !== null : $scope === null) ||
                ($action !== 'attribute' && $scope['owner_assignment_id'] === $assignment->value) ||
                ($action === 'approve_reuse' &&
                    (isset($scope['reuse'][$assignment->value]) || count($scope['reuse']) >= 20)) ||
                ($action === 'revoke_reuse' && !isset($scope['reuse'][$assignment->value]))
            ) {
                self::invalid('Choose a different valid attribution or reuse decision for this coursework.');
            }
            $operation = EntityId::generate();
            $payload = ['decision_id' => $operation->value, 'previous_decision_id' => $root->decision(),
                'action' => $action, 'source' => $this->source($student, $work), 'assignment_id' => $assignment->value,
                'academic_version' => $work['history_version'], 'work_hash' => WorkPursuitLedger::fingerprint($work)];
            // Exercise the same transition validator before returning a confirmation.
            $probe = clone $root;
            $probe->acceptHistorical(\Domain\AcademicRecord\Event\AcademicWorkPursuitWasRecorded::occur(
                new \Domain\AcademicRecord\ValueObject\WorkPursuitId($credit->value),
                $payload,
                ['__aggregate_type' => 'work_pursuit']
            )->withPlayhead($root->playhead() + 1));
            $data = ['kind' => 'academic-work-pursuit-review', 'student_id' => $student->value,
                'credit_id' => $credit->value, 'actor_id' => $actor->value, 'payload' => $payload,
                'expected_version' => $root->playhead(), 'reason' => $reason,
                'effective_at' => $this->clock->now()->format(DATE_ATOM),
                'expires_at' => $this->clock->now()->getTimestamp() + 1200];
            $this->authorize($actor);
            return ['review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                'course_code' => $work['course_code'], 'course_title' => $work['course_title'],
                'action' => $action, 'target' => $target, 'previous' => $root->projection()];
        });
    }

    /** @return array<string,mixed> */
    public function commit(EntityId $student, EntityId $credit, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) || count($d) !== 9 || ($d['kind'] ?? null) !== 'academic-work-pursuit-review' ||
                ($d['student_id'] ?? null) !== $student->value || ($d['credit_id'] ?? null) !== $credit->value ||
                !is_int($d['expires_at'] ?? null) || !is_int($d['expected_version'] ?? null) ||
                $d['expected_version'] < 0 || !is_string($d['reason'] ?? null) ||
                trim($d['reason']) === '' || mb_strlen($d['reason']) > 1000 ||
                !is_array($d['payload'] ?? null) || !is_string($d['effective_at'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($d['actor_id']);
            \Domain\AcademicRecord\ValueObject\WorkPursuitSchema::validate($d['payload']);
            if (
                $d['payload']['source']['student_id'] !== $student->value ||
                $d['payload']['source']['credit_id'] !== $credit->value
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            self::invalid('Review this academic-work pursuit decision again.');
        }
        if ($d['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('This review belongs to another staff member.');
        }
        return $this->transactions->run(function () use ($student, $credit, $d, $actor): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $budget = new HistoryReadBudget();
            $p = $d['payload'];
            $result = $this->ledger->record(
                $credit,
                $p,
                $d['expected_version'],
                new EventContext(
                    $actor,
                    new \DateTimeImmutable($d['effective_at']),
                    new EntityId($p['decision_id']),
                    reason: $d['reason']
                ),
                $budget
            );
            if ($result['new']) {
                if ($d['expires_at'] <= $this->clock->now()->getTimestamp()) {
                    throw new ConcurrentModificationException('This scope review expired. Review it again.');
                }
                $work = $this->work($student, $credit, $budget);
                if (
                    !hash_equals($p['work_hash'], WorkPursuitLedger::fingerprint($work)) ||
                    $p['source'] !== $this->source($student, $work)
                ) {
                    throw new ConcurrentModificationException('Academic work changed. Review its pursuit again.');
                }
                $this->target($student, new EntityId($p['assignment_id']), $p['action'], $budget);
                $this->audit->record($actor, 'academic.work_' . $p['action'], $credit);
            }
            $this->authorize($actor);
            return $result['receipt'];
        });
    }

    /** @return array<string,mixed> */
    private function work(EntityId $student, EntityId $credit, HistoryReadBudget $budget): array
    {
        $inputs = $this->inputs->verify($student, $budget);
        $references = array_column($inputs->references, null, 'credit_id');
        foreach ($inputs->items as $work) {
            if ($work['id'] === $credit->value) {
                $ref = $references[$credit->value] ?? throw new CorruptHistoryException();
                return $work + ['scope_source' => ['student_id' => $student->value, 'credit_id' => $work['id'],
                    'origin' => $ref['origin'], 'source_id' => $ref['source_id']]];
            }
        }
        throw new RecordNotFoundException('Current academic work not found.');
    }
    /** @param array<string,mixed> $work
     * @return array<string,string>
     */
    private function source(EntityId $student, array $work): array
    {
        $source = $work['scope_source'];
        if ($source['student_id'] === $student->value) {
            return $source;
        }
        throw new CorruptHistoryException();
    }
    /** @return array<string,mixed> */
    private function target(
        EntityId $student,
        EntityId $assignment,
        string $action,
        ?HistoryReadBudget $budget = null,
    ): array {
        $p = $this->students->assignment($assignment);
        $this->statuses->verify($p, $budget);
        if ($p->studentId->value !== $student->value || ($action === 'approve_reuse' && $p->status !== 'active')) {
            self::invalid('Choose this student’s pursuit; a new reuse approval requires an active receiving pursuit.');
        }
        $program = $this->students->program($p->programId);
        return ['id' => $p->id->value, 'program_code' => $program['code'], 'program_title' => $program['title'],
            'entry_term_code' => $this->students->term($p->entryTermId)['code'], 'status' => $p->status];
    }
    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'pursuit', 'message' => $message]]);
    }
}
