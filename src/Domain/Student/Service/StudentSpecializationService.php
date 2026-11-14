<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Service\PublishedProgramReader;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Repository\RequirementsRepository;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentSpecializationsWereSelected;
use Domain\Student\Event\StudentSpecializationRequirementsWereAssigned;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Repository\StudentSpecializationRepository;
use Domain\Student\StudentSpecializations;
use Domain\Student\ValueObject\StudentSpecializationSchema;
use Domain\Student\ValueObject\StudentSpecializationRequirementsSchema;
use Domain\Student\ValueObject\StudentSpecializationsId;
use JsonException;
use Qubus\Exception\Data\TypeException;

final readonly class StudentSpecializationService
{
    public function __construct(
        private StudentRecordRepository $records,
        private AdmissionsRepository $admissions,
        private CatalogYearRepository $catalogs,
        private StudentProgramStatusLedger $statuses,
        private PublishedProgramReader $published,
        private StudentSpecializationRepository $selections,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private RequirementsRepository $templates,
        private PublishedRequirementsReader $requirements,
    ) {
    }

    /** Staff read scope without history collection; used before and after an internal progress review. */
    public function authorizeRead(EntityId $student, EntityId $assignment, ?EntityId $actor = null): EntityId
    {
        $actor = $this->authorize($actor);
        $record = $this->admissions->student($student);
        if ($this->records->assignment($assignment)->studentId->value !== $record->id->value) {
            throw new RecordNotFoundException('Student program not found.');
        }
        return $actor;
    }

    /** Internal progress scope; staff management endpoints retain their separate permission checks. */
    public function authorizeProgressRead(EntityId $student, EntityId $assignment, ?EntityId $actor = null): EntityId
    {
        $current = $this->access->authenticatedUserId();
        $record = $this->admissions->student($student);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($record->personId)->ownerUserId,
            'sis:student:read',
        );
        if ($actor !== null && $actor->value !== $current->value) {
            throw new AccessDeniedException('The progress reader changed.');
        }
        if ($this->records->assignment($assignment)->studentId->value !== $student->value) {
            throw new RecordNotFoundException('Student program not found.');
        }
        return $current;
    }

    /** @return array<string,mixed> */
    public function view(EntityId $student, EntityId $assignment): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $assignment, $actor): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $root = $this->load($assignment, $scope, $budget)['root'];
            $program = $this->publication($scope, $budget);
            $old = $root->selection();
            $previous = $old === null ? null : $this->selection($old, $program, $scope, $budget);
            $this->authorize($actor);
            return [
                'assignment_id' => $assignment->value,
                'scope' => $scope,
                'revision' => $this->revision($scope, $root, $program),
                'program' => $program,
                'selection' => $previous,
                'requirements' => array_values($root->requirements()),
                'cohort_changed' => $old !== null && !$this->sameCohort($scope, $old),
                'can_manage' => $this->canManage($actor) && $scope['status'] === 'active' && $program !== null,
            ];
        });
    }

    /**
     * Replace the complete set within this pursuit; an empty set is a deliberate removal, never a deletion.
     * @param list<string> $ids
     * @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $assignment,
        string $revision,
        array $ids,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize(write: true);
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Choose a bounded specialization list.');
        }
        sort($ids, SORT_STRING);
        StudentSpecializationSchema::ids($ids);
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason up to 1,000 characters.'],
            ]);
        }
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $revision,
            $ids,
            $reason,
            $actor,
        ): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $root = $this->load($assignment, $scope, $budget)['root'];
            $program = $this->publication($scope, $budget);
            if (!hash_equals($this->revision($scope, $root, $program), $revision)) {
                throw new ConcurrentModificationException('Reload specialization choices before reviewing.');
            }
            $this->assertSelectable($scope, $program, $ids);
            $old = $root->selection();
            if ($old !== null && $this->sameCohort($scope, $old) && $old['specialization_ids'] === $ids) {
                throw new ValidationException([
                    ['field' => 'specialization_ids', 'message' => 'Choose a changed selection.'],
                ]);
            }
            $payload = StudentSpecializationSchema::read([
                'decision_id' => EntityId::generate()->value,
                'previous_decision_id' => $root->decisionId(),
                'student_id' => $student->value,
                'assignment_id' => $assignment->value,
                'program_id' => $scope['program_id'],
                'academic_year_id' => $scope['academic_year_id'],
                'program_version_id' => $scope['program_version_id'],
                'cohort_binding_revision' => $scope['cohort_binding_revision'],
                'specialization_ids' => $ids,
            ]);
            $data = [
                'purpose' => 'student-specializations',
                'actor_id' => $actor->value,
                'revision' => $revision,
                'expected_version' => $root->playhead(),
                'payload' => $payload,
                'reason' => $reason,
                'expires_at' => $this->clock->now()->getTimestamp() + 1200,
            ];
            $before = $old === null ? null : $this->selection($old, $program, $scope, $budget);
            $this->authorize($actor, true);
            return [
                'before' => $before,
                'after' => $this->summary($payload, $program ?? throw new CorruptHistoryException()),
                'reason' => $reason,
                'review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commit(EntityId $student, EntityId $assignment, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize(write: true);
        try {
            if (strlen($token) > 32768) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) || count($data) !== 7 ||
                ($data['purpose'] ?? null) !== 'student-specializations' ||
                ($data['actor_id'] ?? null) !== $actor->value ||
                !is_string($data['revision'] ?? null) || !is_int($data['expected_version'] ?? null) ||
                $data['expected_version'] < 0 || !is_int($data['expires_at'] ?? null) ||
                !is_string($data['reason'] ?? null) || !is_array($data['payload'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $p = StudentSpecializationSchema::read($data['payload']);
            if ($p['student_id'] !== $student->value || $p['assignment_id'] !== $assignment->value) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'review_token', 'message' => 'Review the selection again.']]);
        }
        return $this->transactions->run(function () use ($student, $assignment, $actor, $data, $p): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $loaded = $this->load($assignment, $scope, $budget, $data);
            if ($loaded['receipt'] !== null) {
                $this->authorize($actor, true);
                return $loaded['receipt'];
            }
            $root = $loaded['root'];
            $program = $this->publication($scope, $budget);
            if (
                $data['expires_at'] <= $this->clock->now()->getTimestamp() ||
                $data['expected_version'] !== $root->playhead() ||
                !hash_equals($data['revision'], $this->revision($scope, $root, $program))
            ) {
                throw new ConcurrentModificationException('This selection review expired or changed. Review it again.');
            }
            $this->assertSelectable($scope, $program, $p['specialization_ids']);
            $operation = new EntityId($p['decision_id']);
            $event = StudentSpecializationsWereSelected::occur($this->stream($assignment)->aggregateId, $p, [
                '__aggregate_type' => 'student_specializations',
            ])->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
            $this->authorize($actor, true);
            if ($data['expires_at'] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException('The selection review expired. Review it again.');
            }
            $receipt = $this->history->append(
                $this->stream($assignment),
                $operation,
                $data['expected_version'],
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    $p['previous_decision_id'] === null ? null : new EntityId($p['previous_decision_id']),
                    $data['reason'],
                ),
                [$event],
            );
            $this->selections->project($assignment, $p, $root->playhead());
            $this->selections->projectRequirements($assignment, $root->requirements());
            $this->audit->record($actor, 'student.specializations_selected', $assignment);
            return $receipt;
        });
    }

    /**
     * Curated, frozen pages; no raw envelopes, actors or protected reasons.
     * @return array<string,mixed> */
    public function history(EntityId $student, EntityId $assignment, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $assignment, $cursor, $actor): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $this->load($assignment, $scope, $budget);
            $page = $this->history->page($this->stream($assignment), $cursor, 10);
            $items = $publications = [];
            foreach ($page->events as $stored) {
                if ($stored->event instanceof StudentSpecializationRequirementsWereAssigned) {
                    $p = StudentSpecializationRequirementsSchema::read($stored->event->payload());
                    $key = $p['program_version_id'];
                    $publications[$key] ??= $this->publication($p, $budget) ?? throw new CorruptHistoryException();
                    $choices = array_column($publications[$key]['specializations']['offered'], null, 'id');
                    $choice = $choices[$p['specialization_id']] ?? throw new CorruptHistoryException();
                    $items[] = [
                        'kind' => 'requirements', 'specialization' => $choice,
                        'requirements_name' => $p['requirements_name'],
                        'requirements_version' => $p['requirements_version'],
                        'version' => $stored->event->playhead(),
                        'effective_at' => $stored->context->effectiveAt?->format(DATE_ATOM),
                        'recorded_at' => $stored->recordedAt->format(DATE_ATOM),
                    ];
                    continue;
                }
                $p = StudentSpecializationSchema::read($stored->event->payload());
                $key = $p['program_version_id'];
                $publications[$key] ??= $this->publication($p, $budget) ?? throw new CorruptHistoryException();
                $items[] = [
                    'kind' => 'choices',
                    'selection' => $this->summary($p, $publications[$key]),
                    'version' => $stored->event->playhead(),
                    'effective_at' => $stored->context->effectiveAt?->format(DATE_ATOM),
                    'recorded_at' => $stored->recordedAt->format(DATE_ATOM),
                ];
            }
            $this->authorize($actor);
            $this->audit->record($actor, 'student.specializations_history_read', $assignment);
            return ['items' => $items, 'next_cursor' => $page->nextCursor];
        });
    }

    /** Paged metadata only; assignment review resolves the complete, protected publication.
     * @return array<string,mixed> */
    public function requirementChoices(
        EntityId $student,
        EntityId $assignment,
        EntityId $specialization,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize(write: true);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $specialization,
            $cursor,
            $actor,
        ): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $root = $this->load($assignment, $scope, $budget)['root'];
            $this->assertRequirementTarget($scope, $root, $specialization);
            $program = $this->publication($scope, $budget) ?? throw new CorruptHistoryException();
            $template = $this->templates->find(
                new EntityId($scope['program_id']),
                new EntityId($scope['academic_year_id']),
                $specialization,
            );
            $page = $template === null ? ['items' => [], 'next_cursor' => null] :
                $this->requirements->page(new EntityId($template['id']), $cursor);
            foreach ($page['items'] as &$item) {
                if ($item['specialization_id'] !== $specialization->value) {
                    throw new CorruptHistoryException();
                }
                $item['assignable'] = $item['program_version_id'] === $scope['program_version_id'];
                $item['assignment_note'] = $item['assignable']
                    ? 'Matches this pursuit’s retained program revision.'
                    : 'This publication belongs to a different program revision.';
            }
            unset($item);
            $this->authorize($actor, true);
            return $page + [
                'revision' => $this->revision($scope, $root, $program),
                'template_id' => $template['id'] ?? null,
                'current' => $root->requirements()[$specialization->value] ?? null,
                'specialization' => array_column(
                    $program['specializations']['offered'],
                    null,
                    'id',
                )[$specialization->value] ?? throw new CorruptHistoryException(),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function assignedRequirements(
        EntityId $student,
        EntityId $assignment,
        EntityId $specialization,
        ?HistoryReadBudget $budget = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $specialization,
            $actor,
            $budget,
        ): array {
            $this->records->lock($student);
            $budget ??= new HistoryReadBudget();
            $all = $this->evaluationContext($student, $assignment, $budget, retainEarlier: true, only: $specialization);
            $scope = $all['scope'];
            $selected = $all['bindings'][$specialization->value] ?? null;
            $requirements = $all['requirements'][$specialization->value] ?? null;
            $this->authorize($actor);
            return [
                'selection' => $selected, 'requirements' => $requirements,
                'cohort_changed' => $selected !== null && !$this->sameCohort($scope, $selected),
                'can_manage' => $this->canManage($actor) && $scope['status'] === 'active' &&
                    $all['selection'] !== null && $this->sameCohort($scope, $all['selection']) &&
                    in_array($specialization->value, $all['selection']['specialization_ids'], true),
            ];
        });
    }

    /**
     * Internal combined-review inputs; caller holds the student lock and shared replay allowance.
     * @return array<string,mixed>
     */
    public function evaluationContext(
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
        bool $retainEarlier = false,
        ?EntityId $only = null,
    ): array {
        $actor = $this->authorizeProgressRead($student, $assignment);
        $scope = $this->scope($student, $assignment, $actor, $budget, progress: true);
        $root = $this->load($assignment, $scope, $budget)['root'];
        $program = $this->publication($scope, $budget);
        $selection = $root->selection();
        $changed = $selection !== null && !$this->sameCohort($scope, $selection);
        $requirements = [];
        if ($retainEarlier || !$changed && $program !== null) {
            if (!$changed && $program !== null && $selection !== null) {
                $this->summary($selection, $program);
            }
            foreach ($root->requirements() as $id => $binding) {
                if ($only === null || $only->value === $id) {
                    $requirements[$id] = $this->bindingPublication($binding, $budget);
                }
            }
        }
        $this->authorizeProgressRead($student, $assignment, $actor);
        return ['scope' => $scope, 'program' => $program, 'selection' => $selection,
            'requirements' => $requirements, 'bindings' => $root->requirements(), 'cohort_changed' => $changed];
    }

    /** @return array<string,mixed> */
    public function reviewRequirements(
        EntityId $student,
        EntityId $assignment,
        EntityId $specialization,
        string $revision,
        EntityId $template,
        EntityId $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize(write: true);
        $reason = trim($reason);
        if (
            $reason === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8') ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason up to 1,000 characters.'],
            ]);
        }
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $specialization,
            $revision,
            $template,
            $version,
            $reason,
            $actor,
        ): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $root = $this->load($assignment, $scope, $budget)['root'];
            $this->assertRequirementTarget($scope, $root, $specialization);
            $program = $this->publication($scope, $budget) ?? throw new CorruptHistoryException();
            if (!hash_equals($this->revision($scope, $root, $program), $revision)) {
                throw new ConcurrentModificationException('Reload the specialization before reviewing requirements.');
            }
            $requirements = $this->requirements->version($template, $version, $budget);
            $this->assertRequirementPublication($scope, $specialization, $requirements);
            $before = $root->requirements()[$specialization->value] ?? null;
            if (
                $before !== null && $before['template_id'] === $template->value &&
                $before['requirements_version_id'] === $version->value
            ) {
                throw new ValidationException([
                    ['field' => 'requirements_version_id', 'message' => 'This publication is already assigned.'],
                ]);
            }
            $p = StudentSpecializationRequirementsSchema::read([
                'decision_id' => EntityId::generate()->value,
                'previous_decision_id' => $root->decisionId(),
                'student_id' => $student->value, 'assignment_id' => $assignment->value,
                'program_id' => $scope['program_id'], 'academic_year_id' => $scope['academic_year_id'],
                'program_version_id' => $scope['program_version_id'],
                'cohort_binding_revision' => $scope['cohort_binding_revision'],
                'specialization_id' => $specialization->value,
                'selection_decision_id' => ($root->selection() ?? throw new CorruptHistoryException())['decision_id'],
                'template_id' => $template->value, 'requirements_version_id' => $version->value,
                'requirements_version' => $requirements['version'],
                'requirements_name' => $requirements['definition']['name'],
            ]);
            $data = ['purpose' => 'student-specialization-requirements', 'actor_id' => $actor->value,
                'revision' => $revision, 'expected_version' => $root->playhead(), 'payload' => $p,
                'reason' => $reason, 'expires_at' => $this->clock->now()->getTimestamp() + 1200];
            $this->authorize($actor, true);
            return ['before' => $before, 'requirements' => $requirements, 'reason' => $reason,
                'review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }

    /** @return array<string,mixed> */
    public function assignRequirements(
        EntityId $student,
        EntityId $assignment,
        EntityId $specialization,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize(write: true);
        try {
            if (strlen($token) > 32768) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) || count($data) !== 7 ||
                ($data['purpose'] ?? null) !== 'student-specialization-requirements' ||
                ($data['actor_id'] ?? null) !== $actor->value || !is_string($data['revision'] ?? null) ||
                !is_int($data['expected_version'] ?? null) || $data['expected_version'] < 1 ||
                !is_string($data['reason'] ?? null) || !is_int($data['expires_at'] ?? null) ||
                !is_array($data['payload'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $p = StudentSpecializationRequirementsSchema::read($data['payload']);
            if (
                $p['student_id'] !== $student->value || $p['assignment_id'] !== $assignment->value ||
                $p['specialization_id'] !== $specialization->value
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new ValidationException([
                ['field' => 'review_token', 'message' => 'Review the requirements assignment again.'],
            ]);
        }
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $specialization,
            $actor,
            $data,
            $p,
        ): array {
            $this->records->lock($student);
            $budget = new HistoryReadBudget();
            $scope = $this->scope($student, $assignment, $actor, $budget);
            $loaded = $this->load($assignment, $scope, $budget, $data);
            if ($loaded['receipt'] !== null) {
                $this->authorize($actor, true);
                return $loaded['receipt'];
            }
            $root = $loaded['root'];
            $this->assertRequirementTarget($scope, $root, $specialization);
            $program = $this->publication($scope, $budget) ?? throw new CorruptHistoryException();
            if (
                $data['expires_at'] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $data['expected_version'] ||
                !hash_equals($data['revision'], $this->revision($scope, $root, $program))
            ) {
                throw new ConcurrentModificationException('The requirements review expired or changed. Review again.');
            }
            $requirements = $this->bindingPublication($p, $budget);
            $this->assertRequirementPublication($scope, $specialization, $requirements);
            $event = StudentSpecializationRequirementsWereAssigned::occur($this->stream($assignment)->aggregateId, $p, [
                '__aggregate_type' => 'student_specializations',
            ])->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
            $this->authorize($actor, true);
            if ($data['expires_at'] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException('The requirements review expired. Review again.');
            }
            $operation = new EntityId($p['decision_id']);
            $receipt = $this->history->append(
                $this->stream($assignment),
                $operation,
                $data['expected_version'],
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    new EntityId($p['previous_decision_id']),
                    $data['reason'],
                ),
                [$event]
            );
            $this->selections->project(
                $assignment,
                $root->selection() ?? throw new CorruptHistoryException(),
                $root->playhead(),
            );
            $this->selections->projectRequirements($assignment, $root->requirements());
            $this->audit->record($actor, 'student.specialization_requirements_assigned', $assignment);
            return $receipt;
        });
    }

    /** @param array<string,mixed> $scope */
    private function assertRequirementTarget(array $scope, StudentSpecializations $root, EntityId $specialization): void
    {
        $selection = $root->selection();
        if (
            $scope['status'] !== 'active' || $selection === null || !$this->sameCohort($scope, $selection) ||
            !in_array($specialization->value, $selection['specialization_ids'], true)
        ) {
            throw new ValidationException([['field' => 'specialization_id', 'message' =>
                'Use an active pursuit and review its selected specializations against the current cohort first.']]);
        }
    }
    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $requirements */
    private function assertRequirementPublication(array $scope, EntityId $specialization, array $requirements): void
    {
        if (
            $requirements['program_id'] !== $scope['program_id'] ||
            $requirements['academic_year_id'] !== $scope['academic_year_id'] ||
            $requirements['program_version_id'] !== $scope['program_version_id'] ||
            ($requirements['specialization_id'] ?? null) !== $specialization->value ||
            $requirements['schema_version'] !== 4
        ) {
            throw new ValidationException([['field' => 'requirements_version_id', 'message' =>
                'Choose published requirements for this specialization and this pursuit’s retained program revision.',
            ]]);
        }
    }
    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed> */
    private function bindingPublication(array $p, HistoryReadBudget $budget): array
    {
        $requirements = $this->requirements->version(
            new EntityId($p['template_id']),
            new EntityId($p['requirements_version_id']),
            $budget,
        );
        if (
            $requirements['program_id'] !== $p['program_id'] ||
            $requirements['academic_year_id'] !== $p['academic_year_id'] ||
            $requirements['program_version_id'] !== $p['program_version_id'] ||
            ($requirements['specialization_id'] ?? null) !== $p['specialization_id'] ||
            $requirements['version'] !== $p['requirements_version'] ||
            $requirements['definition']['name'] !== $p['requirements_name']
        ) {
            throw new CorruptHistoryException();
        }
        return $requirements;
    }

    private function authorize(?EntityId $actor = null, bool $write = false): EntityId
    {
        $current = $this->access->requirePermission('sis:student:read');
        if (
            ($actor !== null && $current->value !== $actor->value) ||
            ($write && $this->access->requirePermission('sis:degree-audit:assign')->value !== $current->value)
        ) {
            throw new AccessDeniedException('The selection actor changed.');
        }
        return $current;
    }
    private function canManage(EntityId $actor): bool
    {
        try {
            $this->authorize($actor, true);
            return true;
        } catch (AccessDeniedException) {
            $this->authorize($actor);
            return false;
        }
    }
    /** @return array<string,mixed> */
    private function scope(
        EntityId $student,
        EntityId $assignment,
        EntityId $actor,
        HistoryReadBudget $budget,
        bool $progress = false,
    ): array {
        if ($progress) {
            $this->authorizeProgressRead($student, $assignment, $actor);
        } else {
            $this->authorize($actor);
        }
        $record = $this->admissions->student($student);
        $p = $this->records->assignment($assignment);
        if ($p->studentId->value !== $record->id->value) {
            throw new RecordNotFoundException('Student program not found.');
        }
        $position = $this->statuses->verify($p, $budget);
        $binding = $this->catalogs->binding($assignment);
        $catalog = $binding === null ? null : $this->catalogs->release(new EntityId($binding['catalog_id']));
        if ($catalog !== null && $catalog['program_id'] !== $p->programId->value) {
            throw new CorruptHistoryException();
        }
        return [
            'student_id' => $student->value,
            'assignment_id' => $assignment->value,
            'program_id' => $p->programId->value,
            'entry_term_id' => $p->entryTermId->value,
            'status' => $p->status,
            'status_position' => $position,
            'academic_year_id' => $catalog['year_id'] ?? null,
            'program_version_id' => $binding['catalog_id'] ?? null,
            'cohort_binding_revision' => $binding['version'] ?? null,
        ];
    }
    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>|null */
    private function publication(array $scope, HistoryReadBudget $budget): ?array
    {
        if ($scope['program_version_id'] === null || $scope['academic_year_id'] === null) {
            return null;
        }
        return $this->published->version(
            new EntityId($scope['program_id']),
            new EntityId($scope['academic_year_id']),
            new EntityId($scope['program_version_id']),
            $budget,
        );
    }
    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed>|null $program
     * @param list<string> $ids */
    private function assertSelectable(array $scope, ?array $program, array $ids): void
    {
        if ($scope['status'] !== 'active' || $program === null) {
            throw new ValidationException([
                ['field' => 'specialization_ids', 'message' =>
                    'Use an active pursuit with a retained program publication before selecting specializations.'],
            ]);
        }
        $snapshot = $program['specializations'];
        $limits = $snapshot['effective'];
        if (
            count($ids) < $limits['minimum'] || count($ids) > $limits['maximum'] ||
            array_diff($ids, array_column($snapshot['offered'], 'id')) !== []
        ) {
            throw new ValidationException([
                ['field' => 'specialization_ids', 'message' =>
                    'Choose offered specializations within this published program’s required and maximum counts.'],
            ]);
        }
    }
    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $program
     * @return array<string,mixed> */
    private function summary(array $p, array $program): array
    {
        if ($program['program_id'] !== $p['program_id'] || $program['academic_year_id'] !== $p['academic_year_id']) {
            throw new CorruptHistoryException();
        }
        $limits = $program['specializations']['effective'];
        $offered = array_column($program['specializations']['offered'], null, 'id');
        $ids = $p['specialization_ids'];
        if (
            count($ids) < $limits['minimum'] || count($ids) > $limits['maximum'] ||
            array_diff($ids, array_keys($offered)) !== []
        ) {
            throw new CorruptHistoryException();
        }
        return [
            'program_version_id' => $p['program_version_id'],
            'academic_year_code' => $program['academic_year_code'],
            'catalog_revision' => $program['catalog_revision'],
            'program_code' => $program['definition']['code'],
            'program_title' => $program['definition']['title'],
            'limits' => $limits,
            'specializations' => array_values(array_intersect_key($offered, array_fill_keys($ids, true))),
        ];
    }
    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed>|null $current
     * @param array<string,mixed> $scope
     * @return array<string,mixed> */
    private function selection(array $p, ?array $current, array $scope, HistoryReadBudget $budget): array
    {
        $program = $current !== null && $p['program_version_id'] === $scope['program_version_id']
        ? $current : $this->publication($p, $budget);
        return $this->summary($p, $program ?? throw new CorruptHistoryException());
    }
    /** @param array<string,mixed> $scope
     * @param array<string,mixed> $p */
    private function sameCohort(array $scope, array $p): bool
    {
        return $scope['program_version_id'] === $p['program_version_id'] &&
        $scope['cohort_binding_revision'] === $p['cohort_binding_revision'];
    }
    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed>|null $program
     * @throws JsonException
     */
    private function revision(array $scope, StudentSpecializations $root, ?array $program): string
    {
        return hash('sha256', json_encode([$scope, $root->playhead(), $program], JSON_THROW_ON_ERROR));
    }
    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed>|null $review
     * @return array{root:StudentSpecializations,receipt:?array<string,mixed>}
     * @throws TypeException
     */
    private function load(EntityId $assignment, array $scope, HistoryReadBudget $budget, ?array $review = null): array
    {
        $root = StudentSpecializations::root(new StudentSpecializationsId($assignment->value));
        $cursor = $receipt = null;
        do {
            $page = $budget->page($this->history, $this->stream($assignment), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event instanceof StudentSpecializationRequirementsWereAssigned
                ? StudentSpecializationRequirementsSchema::read($stored->event->payload())
                : StudentSpecializationSchema::read($stored->event->payload());
                if ($p['student_id'] !== $scope['student_id'] || $p['program_id'] !== $scope['program_id']) {
                    throw new CorruptHistoryException();
                }
                if ($review !== null && $p['decision_id'] === $review['payload']['decision_id']) {
                    if (
                        $p !== ($stored->event instanceof StudentSpecializationRequirementsWereAssigned
                            ? StudentSpecializationRequirementsSchema::read($review['payload'])
                            : StudentSpecializationSchema::read($review['payload'])) ||
                        $stored->context->actor?->value !== $review['actor_id'] ||
                        $stored->context->reason !== $review['reason'] ||
                        $stored->event->playhead() !== $review['expected_version'] + 1
                    ) {
                        throw new ConcurrentModificationException('This operation belongs to another decision.');
                    }
                    $receipt = ['operation_id' => $p['decision_id'], 'first_version' => $root->playhead(),
                        'last_version' => $root->playhead(), 'event_ids' => [(string) $stored->event->eventId()]];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $expected = $root->selection() === null ? null : [
            'payload' => $root->selection(), 'position' => $root->playhead(),
        ];
        if (
            $this->selections->get($assignment) !== $expected ||
            $this->selections->requirements($assignment) !== $root->requirements()
        ) {
            throw new CorruptHistoryException();
        }
        return ['root' => $root, 'receipt' => $receipt];
    }
    /**
     * @throws TypeException
     */
    private function stream(EntityId $assignment): StreamReference
    {
        return new StreamReference(new StudentSpecializationsId($assignment->value), 'student_specializations');
    }
}
