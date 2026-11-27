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
        private \Domain\Graduation\Service\GraduationReader $graduation,
    ) {
    }

    /** Internal creator selection, after the caller authorizes and locks the student.
     * Primary selection is a default for a new decision, never historical attribution evidence.
     */
    public function activePursuit(EntityId $student, ?EntityId $assignment = null): EntityId
    {
        return $this->activeSelection($student, $assignment)->id;
    }

    /** One verified selection supplies both pursuit and actual program, including explicitly selected active pursuits. */
    public function activeSelection(EntityId $student, ?EntityId $assignment = null): \Domain\Student\StudentProgram
    {
        $assignment ??= new EntityId($this->primary->current($this->admissions->student($student))['assignment_id']);
        $record = $this->students->assignment($assignment);
        $this->statuses->verify($record);
        if ($record->studentId->value !== $student->value || $record->status !== 'active') {
            self::invalid('Choose an active program pursuit belonging to this student.');
        }
        if ($this->graduation->load($student, $record->id, new HistoryReadBudget())->state()['credential'] !== null) {
            self::invalid('An awarded pursuit cannot receive new registrations or academic work.');
        }
        return $record;
    }

    /** Internal filter, under the authorized student lock and caller's shared replay allowance. */
    public function forPursuit(
        AcademicInputSet $inputs,
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
    ): AcademicInputSet {
        $graduation = $this->graduation->student($student, $budget);
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
            if (
                !$root->appliesTo($assignment->value) ||
                \Domain\Graduation\Service\GraduationReader::transferRequired(
                    $graduation,
                    $assignment->value,
                    $scope,
                    $reference['credit_id'],
                )
            ) {
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
        return $root->appliesTo($assignment->value) &&
        !\Domain\Graduation\Service\GraduationReader::transferRequired(
            $this->graduation->student(new EntityId($p['source']['student_id']), $budget),
            $assignment->value,
            $p,
            $credit['id'],
        );
    }

    /**
     * Bounded office-only choices, including unknown or other-pursuit work. Never used by an audit.
     * @return array<string,mixed>
     */
    public function page(EntityId $student, ?EntityId $after = null, ?EntityId $assignment = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $after, $actor, $assignment): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $this->admissions->student($student);
            $budget = new HistoryReadBudget();
            $graduation = $this->graduation->student($student, $budget);
            $receiving = $assignment === null ? null : $this->target($student, $assignment, 'read', $budget);
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
                    'reuse_assignment_ids' => $p === null ? [] : array_keys($p['reuse']),
                    'transfer_required' => $assignment !== null && $p !== null &&
                        \Domain\Graduation\Service\GraduationReader::transferRequired(
                            $graduation,
                            $assignment->value,
                            $p,
                            $work['id'],
                        )];
            }
            $this->authorize($actor);
            return ['items' => $items, 'receiving' => $receiving,
                'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
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

    /** Office-only decision references, never raw events or protected approval narratives.
     * @return array<string,mixed>
     */
    public function history(EntityId $student, EntityId $credit, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $credit, $cursor, $actor): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $budget = new HistoryReadBudget();
            // Current verified work is required: restricted early removals have no office work card.
            $work = $this->work($student, $credit, $budget);
            $page = $this->ledger->timeline($credit, $this->source($student, $work), $budget, $cursor);
            $items = $pursuits = [];
            foreach ($page['items'] as $item) {
                $id = $item['assignment_id'];
                $pursuits[$id] ??= $this->target($student, new EntityId($id), 'read', $budget);
                unset($item['assignment_id']);
                $items[] = $item + ['pursuit' => $pursuits[$id]];
            }
            $this->authorize($actor);
            $this->audit->record($actor, 'academic.work_pursuit_history_read', $credit);
            $this->authorize($actor);
            return ['items' => $items, 'next_cursor' => $page['next_cursor'],
                'through_version' => $page['through_version'], 'pursuit_labels' => 'current'];
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
        $reason = self::reason($reason);
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
            $entry = $this->reviewedEntry(
                $student,
                $credit,
                $assignment,
                $action,
                $work,
                EntityId::generate(),
                $budget
            );
            $payload = $entry['payload'];
            $data = ['kind' => 'academic-work-pursuit-review', 'student_id' => $student->value,
                'credit_id' => $credit->value, 'actor_id' => $actor->value, 'payload' => $payload,
                'expected_version' => $entry['expected_version'], 'reason' => $reason,
                'effective_at' => $this->clock->now()->format(DATE_ATOM),
                'expires_at' => $this->clock->now()->getTimestamp() + 1200];
            $this->authorize($actor);
            return ['review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                'course_code' => $work['course_code'], 'course_title' => $work['course_title'],
                'action' => $action, 'target' => $target, 'previous' => $entry['previous']];
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
        $receipts = $this->confirmSelection(
            $student,
            [['credit_id' => $credit->value,
            'payload' => $d['payload'], 'expected_version' => $d['expected_version']]],
            $actor,
            $d['reason'],
            $d['effective_at'],
            $d['expires_at'],
            new EntityId($d['payload']['decision_id'])
        );
        return $receipts[0]['receipt'];
    }

    /** One evidence collection and one review for a deliberately selected set of earlier records.
     * @param array<mixed> $credits
     * @return array<string,mixed>
     */
    public function reviewReuse(
        EntityId $student,
        EntityId $assignment,
        array $credits,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $ids = self::selectedIds($credits);
        $reason = self::reason($reason);
        return $this->transactions->run(function () use ($student, $assignment, $ids, $reason, $actor): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $budget = new HistoryReadBudget();
            $target = $this->target($student, $assignment, 'approve_reuse', $budget);
            $works = $this->works($student, $budget);
            $operation = EntityId::generate();
            $entries = $items = $owners = [];
            foreach ($ids as $credit) {
                $work = $works[$credit->value] ?? throw new RecordNotFoundException('Current academic work not found.');
                $entry = $this->reviewedEntry(
                    $student,
                    $credit,
                    $assignment,
                    'approve_reuse',
                    $work,
                    self::reuseOperation($operation, $credit),
                    $budget
                );
                $ownerId = $entry['previous']['owner_assignment_id'];
                $owners[$ownerId] ??= $this->target($student, new EntityId($ownerId), 'read', $budget);
                $items[] = ['id' => $credit->value, 'course_code' => $work['course_code'],
                    'course_title' => $work['course_title'], 'term_code' => $work['term_code'],
                    'credit_type' => $work['credit_type'], 'owner' => $owners[$ownerId]];
                unset($entry['previous']);
                $entries[] = $entry;
            }
            $data = ['kind' => 'academic-work-reuse-review', 'student_id' => $student->value,
                'assignment_id' => $assignment->value, 'actor_id' => $actor->value,
                'operation_id' => $operation->value, 'decisions' => $entries, 'reason' => $reason,
                'effective_at' => $this->clock->now()->format(DATE_ATOM),
                'expires_at' => $this->clock->now()->getTimestamp() + 1200];
            $this->authorize($actor);
            return ['review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                'target' => $target, 'items' => $items];
        });
    }

    /** @return array<string,mixed> */
    public function commitReuse(EntityId $student, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 65536) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) || count($d) !== 9 || ($d['kind'] ?? null) !== 'academic-work-reuse-review' ||
                ($d['student_id'] ?? null) !== $student->value || !is_array($d['decisions'] ?? null) ||
                !is_int($d['expires_at'] ?? null) || !is_string($d['effective_at'] ?? null) ||
                !is_string($d['reason'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            self::reason($d['reason']);
            $assignment = new EntityId($d['assignment_id']);
            $operation = new EntityId($d['operation_id']);
            new EntityId($d['actor_id']);
            if (!array_is_list($d['decisions'])) {
                throw new \InvalidArgumentException();
            }
            $ids = self::selectedIds(array_map(
                static fn(array $entry): mixed => $entry['credit_id'] ?? null,
                $d['decisions']
            ));
            foreach ($d['decisions'] as $i => $entry) {
                if (
                    count($entry) !== 3 || !is_int($entry['expected_version'] ?? null) ||
                    $entry['expected_version'] < 1 || !is_array($entry['payload'] ?? null) ||
                    $entry['credit_id'] !== $ids[$i]->value
                ) {
                    throw new \InvalidArgumentException();
                }
                $p = $entry['payload'];
                \Domain\AcademicRecord\ValueObject\WorkPursuitSchema::validate($p);
                if (
                    $p['action'] !== 'approve_reuse' || $p['assignment_id'] !== $assignment->value ||
                    $p['decision_id'] !== self::reuseOperation($operation, $ids[$i])->value ||
                    $p['source']['student_id'] !== $student->value || $p['source']['credit_id'] !== $ids[$i]->value
                ) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\Throwable) {
            self::invalid('Review this selection of earlier coursework again.');
        }
        if ($d['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('This review belongs to another staff member.');
        }
        $receipts = $this->confirmSelection(
            $student,
            $d['decisions'],
            $actor,
            $d['reason'],
            $d['effective_at'],
            $d['expires_at'],
            $operation
        );
        return ['operation_id' => $operation->value, 'assignment_id' => $assignment->value,
            'approved' => count($receipts), 'decisions' => $receipts];
    }

    /** @param array<mixed> $values
     * @return list<EntityId>
     */
    private static function selectedIds(array $values): array
    {
        if (!array_is_list($values) || count($values) < 1 || count($values) > 20) {
            self::invalid('Choose between one and twenty academic records for this review.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value) || isset($ids[$value])) {
                self::invalid('Choose distinct academic records from this student.');
            }
            $ids[$value] = new EntityId($value);
        }
        ksort($ids, SORT_STRING);
        return array_values($ids);
    }

    private static function reuseOperation(EntityId $operation, EntityId $credit): EntityId
    {
        return \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion($operation, 'work.reuse.' . $credit->value);
    }

    /** @param array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function reviewedEntry(
        EntityId $student,
        EntityId $credit,
        EntityId $assignment,
        string $action,
        array $work,
        EntityId $operation,
        HistoryReadBudget $budget,
    ): array {
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
        $this->graduationGuard($student, $credit, $assignment, $action, $scope, $budget);
        $payload = ['decision_id' => $operation->value, 'previous_decision_id' => $root->decision(),
            'action' => $action, 'source' => $this->source($student, $work), 'assignment_id' => $assignment->value,
            'academic_version' => $work['history_version'], 'work_hash' => WorkPursuitLedger::fingerprint($work)];
        $probe = clone $root;
        $probe->acceptHistorical(\Domain\AcademicRecord\Event\AcademicWorkPursuitWasRecorded::occur(
            new \Domain\AcademicRecord\ValueObject\WorkPursuitId($credit->value),
            $payload,
            ['__aggregate_type' => 'work_pursuit']
        )->withPlayhead($root->playhead() + 1));
        return ['credit_id' => $credit->value, 'payload' => $payload,
            'expected_version' => $root->playhead(), 'previous' => $scope];
    }

    /** Both single-record and grouped confirmations use this one transaction/evidence/receipt path.
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private function confirmSelection(
        EntityId $student,
        array $entries,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        string $effective,
        int $expires,
        EntityId $correlation,
    ): array {
        return $this->transactions->run(function () use (
            $student,
            $entries,
            $actor,
            $reason,
            $effective,
            $expires,
            $correlation
        ): array {
            $this->students->lock($student);
            $this->authorize($actor);
            $budget = new HistoryReadBudget();
            $works = null;
            $targets = $receipts = [];
            $accepted = null;
            foreach ($entries as $entry) {
                $credit = new EntityId($entry['credit_id']);
                $p = $entry['payload'];
                $result = $this->ledger->record(
                    $credit,
                    $p,
                    $entry['expected_version'],
                    new EventContext($actor, new \DateTimeImmutable($effective), $correlation, reason: $reason),
                    $budget
                );
                if ($accepted !== null && $accepted !== $result['new']) {
                    // A group can only have committed together. Never repair a partial group from this web command.
                    throw new CorruptHistoryException();
                }
                $accepted = $result['new'];
                if ($result['new']) {
                    $this->graduationGuard(
                        $student,
                        $credit,
                        new EntityId($p['assignment_id']),
                        $p['action'],
                        $this->ledger->load($credit, $budget)->projection(),
                        $budget
                    );
                    if ($expires <= $this->clock->now()->getTimestamp()) {
                        throw new ConcurrentModificationException('This scope review expired. Review it again.');
                    }
                    $works ??= $this->works($student, $budget);
                    $work = $works[$credit->value] ??
                        throw new RecordNotFoundException('Current academic work not found.');
                    if (
                        !hash_equals($p['work_hash'], WorkPursuitLedger::fingerprint($work)) ||
                        $p['source'] !== $this->source($student, $work)
                    ) {
                        throw new ConcurrentModificationException('Academic work changed. Review its pursuit again.');
                    }
                    $key = $p['assignment_id'] . ':' . $p['action'];
                    if (!isset($targets[$key])) {
                        $this->target($student, new EntityId($p['assignment_id']), $p['action'], $budget);
                        $targets[$key] = true;
                    }
                    $this->audit->record($actor, 'academic.work_' . $p['action'], $credit);
                    $this->graduationGuard(
                        $student,
                        $credit,
                        new EntityId($p['assignment_id']),
                        $p['action'],
                        $this->ledger->load($credit, $budget)->projection(),
                        $budget
                    );
                }
                $receipts[] = ['credit_id' => $credit->value, 'receipt' => $result['receipt']];
            }
            $this->authorize($actor);
            if ($accepted && $expires <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException('This scope review expired. Review it again.');
            }
            return $receipts;
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function works(EntityId $student, HistoryReadBudget $budget): array
    {
        $inputs = $this->inputs->verify($student, $budget);
        $references = array_column($inputs->references, null, 'credit_id');
        $works = [];
        foreach ($inputs->items as $work) {
            $ref = $references[$work['id']] ?? throw new CorruptHistoryException();
            $works[$work['id']] = $work + ['scope_source' => ['student_id' => $student->value,
                'credit_id' => $work['id'], 'origin' => $ref['origin'], 'source_id' => $ref['source_id']]];
        }
        return $works;
    }

    private static function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8') ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            self::invalid('Explain the academic-work scope decision in at most 1,000 characters.');
        }
        return $reason;
    }

    /** @return array<string,mixed> */
    private function work(EntityId $student, EntityId $credit, HistoryReadBudget $budget): array
    {
        return $this->works($student, $budget)[$credit->value] ??
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
    private function graduationGuard(
        EntityId $student,
        EntityId $credit,
        EntityId $target,
        string $action,
        ?array $scope,
        HistoryReadBudget $budget
    ): void {
        if (!in_array($action, ['approve_reuse', 'correct_attribution', 'attribute'], true)) {
            return;
        }
        $states = $this->graduation->student($student, $budget);
        if (
            ($states[$target->value]['credential'] ?? null) !== null ||
            ($scope !== null && \Domain\Graduation\Service\GraduationReader::transferRequired(
                $states,
                $target->value,
                $scope,
                $credit->value,
            ))
        ) {
            self::invalid(
                'Work from a graduated pursuit requires formal transfer evaluation into the receiving pursuit.'
            );
        }
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'pursuit', 'message' => $message]]);
    }
}
