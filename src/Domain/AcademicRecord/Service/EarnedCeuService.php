<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\EarnedCeusWereDecided;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\AcademicRecord\ValueObject\EarnedCeus;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Explicit registrar decisions, independent of academic-credit and grade calculations. */
final readonly class EarnedCeuService
{
    public function __construct(
        private InstitutionalCreditLedger $credits,
        private AcademicCreditRepository $records,
        private HistoricalEventStore $history,
        private RetainedSectionReader $sections,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\Audit\Repository\ActorDirectory $actors,
    ) {
    }

    public function records(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->authorize(false);
        return $this->transactions->run(function () use ($student, $after, $actor): array {
            $this->records->lockStudent($student);
            $result = $this->records->ceuRecords($student, $after);
            $this->sameActor($actor, false);
            return $result;
        });
    }

    public function view(EntityId $student, EntityId $section, EntityId $enrollment): array
    {
        $actor = $this->authorize(false);
        return $this->transactions->run(function () use ($student, $section, $enrollment, $actor): array {
            $this->lock($student, $section, $actor, false);
            [, $root, $facts, $offered] = $this->source($student, $section, $enrollment, new HistoryReadBudget());
            $canRecord = false;
            $canManage = false;
            try {
                $this->sameActor($actor, true, $facts['status'] === 'W');
                $canManage = true;
                $canRecord = $this->eligible($root, $offered);
            } catch (AccessDeniedException) {
            }
            $this->sameActor($actor, false);
            return $this->summary($root, $facts, $offered) + ['can_record' => $canRecord, 'can_manage' => $canManage,
                'today' => $this->clock->now()->format('Y-m-d')];
        });
    }

    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        int $position,
        ?int $earnedMilli,
        string $effectiveOn,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
        ?string $completedOn,
    ): array {
        $actor = $this->authorize(true);
        $reason = $this->reason($reason, $acknowledged);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $version,
            $position,
            $earnedMilli,
            $effectiveOn,
            $reason,
            $acknowledged,
            $completedOn,
            $actor,
        ): array {
            $this->lock($student, $section, $actor, true);
            [, $root, $facts, $offered] = $this->source($student, $section, $enrollment, new HistoryReadBudget());
            $this->sameActor($actor, true, $facts['status'] === 'W');
            $award = $this->fresh(
                $root,
                $facts,
                $offered,
                $version,
                $position,
                $earnedMilli,
                $effectiveOn,
                $completedOn,
            );
            $operation = EntityId::generate();
            $command = ['operation_id' => $operation->value, 'expected_version' => $version,
                'expected_position' => $position, 'previous_award' => $root->earnedCeus(), 'award' => $award,
                'effective_on' => $effectiveOn, 'reason' => $reason, 'acknowledged' => $acknowledged];
            $intent = $this->intent($student, $section, $enrollment, $command);
            $this->sameActor($actor, true, $facts['status'] === 'W');
            return $this->summary($root, $facts, $offered) + ['command' => $command,
                'review_token' => $this->proofs->issue(
                    'earned-ceus',
                    'staff',
                    $enrollment,
                    $actor,
                    $operation,
                    $intent,
                )];
        });
    }

    public function confirm(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] array $command,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize(true);
        $keys = ['operation_id', 'expected_version', 'expected_position', 'previous_award', 'award',
            'effective_on', 'reason', 'acknowledged'];
        if (
            count($command) !== count($keys) || array_diff(array_keys($command), $keys) !== [] ||
            !is_string($command['operation_id'] ?? null) || !is_int($command['expected_version'] ?? null) ||
            !is_int($command['expected_position'] ?? null) || !is_array($command['award'] ?? null) ||
            !is_string($command['effective_on'] ?? null) || !is_string($command['reason'] ?? null) ||
            ($command['acknowledged'] ?? null) !== true
        ) {
            throw new ValidationException([['field' => 'command', 'message' => trans(
                'Review the earned CEUs again.',
            )]]);
        }
        $operation = new EntityId($command['operation_id']);
        $award = new EarnedCeus($command['award'])->facts;
        $this->reason($command['reason'], true);
        $intent = $this->intent($student, $section, $enrollment, $command);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $command,
            $token,
            $actor,
            $operation,
            $award,
            $intent,
        ): array {
            $this->lock($student, $section, $actor, true);
            $budget = new HistoryReadBudget();
            [$id, $root, $facts, $offered] = $this->source($student, $section, $enrollment, $budget, true);
            $withdrawn = $facts['status'] === 'W';
            $closed = in_array($facts['status'], ['W', 'D'], true);
            $this->sameActor($actor, true, $closed);
            $accepted = $root->ceuDecision($operation);
            $this->proofs->validate(
                $token,
                'earned-ceus',
                'staff',
                $enrollment,
                $actor,
                $operation,
                $intent,
                $accepted !== null
            );
            if ($accepted !== null) {
                if (!hash_equals($accepted['intent'], $intent) || $this->history->verifyOperation($operation) !== 1) {
                    throw new ConcurrentModificationException(trans('This operation belongs to another CEU decision.'));
                }
                $this->sameActor($actor, true, $closed);
                return $accepted['receipt'];
            }
            $current = $this->fresh(
                $root,
                $facts,
                $offered,
                $command['expected_version'],
                $command['expected_position'],
                $award['earned_milli'],
                $command['effective_on'],
                $award['completed_on'],
            );
            if (
                !EarnedCeus::same($current, $award) ||
                !EarnedCeus::same($root->earnedCeus(), $command['previous_award'])
            ) {
                throw new ConcurrentModificationException(trans('The earned CEU basis changed. Review again.'));
            }
            $event = EarnedCeusWereDecided::occur(new AcademicCreditId($id->value), [
                'decision_id' => $operation->value, 'previous_decision_id' => $root->decision(),
                'previous_award' => $root->earnedCeus(), 'award' => $award, 'intent' => $intent,
            ], ['__aggregate_type' => 'academic_credit', 'independent_ceu_completion' => true] +
                ($withdrawn ? ['withdrawn_ceu_decision' => true] : []))->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
            $receipt = $this->history->append(
                $this->stream($id),
                $operation,
                $command['expected_position'],
                new EventContext(
                    $actor,
                    new DateTimeImmutable($command['effective_on'], $this->clock->now()->getTimezone()),
                    $operation,
                    new EntityId($event->payload()['previous_decision_id']),
                    $command['reason']
                ),
                [$event]
            );
            $this->records->project(
                $root->credit() ?? throw new CorruptHistoryException(),
                $section,
                $facts['version'],
            );
            $this->records->recordEarnedCeus($id, $award);
            $this->audit->record($actor, 'academic.earned_ceus_decided', $id);
            [, $after, $projected, $finalOffered] = $this->source($student, $section, $enrollment, $budget);
            if (
                !EarnedCeus::same($after->earnedCeus(), $award) ||
                $after->playhead() !== $command['expected_position'] + 1 ||
                $projected !== array_replace($facts, ['version' => $facts['version'] + 1]) || $finalOffered !== $offered
            ) {
                throw new CorruptHistoryException();
            }
            $this->proofs->validate($token, 'earned-ceus', 'staff', $enrollment, $actor, $operation, $intent);
            $this->sameActor($actor, true, $closed);
            return $receipt;
        });
    }

    public function history(EntityId $student, EntityId $section, EntityId $enrollment, ?string $cursor = null): array
    {
        $actor = $this->authorize(true);
        return $this->transactions->run(function () use ($student, $section, $enrollment, $cursor, $actor): array {
            $this->lock($student, $section, $actor, true);
            [$id, , $facts] = $this->source($student, $section, $enrollment, new HistoryReadBudget(), true);
            $closed = in_array($facts['status'], ['W', 'D'], true);
            $this->sameActor($actor, true, $closed);
            $page = $this->history->page($this->stream($id), $cursor, 10);
            $items = [];
            foreach ($page->events as $stored) {
                if (!$stored->event instanceof EarnedCeusWereDecided) {
                    continue;
                }
                $payload = $stored->event->payload();
                $items[] = ['before' => $payload['previous_award'], 'after' => $payload['award'],
                    'effective_at' => $stored->context->effectiveAt?->format(DATE_ATOM),
                    'recorded_at' => $stored->recordedAt->format(DATE_ATOM), 'reason' => $stored->context->reason,
                    'actor_id' => $stored->context->actor?->value, 'position' => $stored->event->playhead()];
            }
            $names = $this->actors->names(array_values(array_map(
                static fn (string $id): EntityId => new EntityId($id),
                array_unique(array_filter(array_column($items, 'actor_id'))),
            )));
            foreach ($items as &$item) {
                $item['actor_name'] = $names[$item['actor_id']] ?? null;
            }
            unset($item);
            $this->sameActor($actor, true, $closed);
            return ['items' => $items, 'next_cursor' => $page->nextCursor];
        });
    }

    private function source(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        HistoryReadBudget $budget,
        bool $allowRemoved = false
    ): array {
        [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment, $allowRemoved, $budget);
        $registered = $this->records->enrollment($enrollment);
        $removed = $registered === null && $allowRemoved && $facts['status'] === 'D';
        $sameScope = $registered !== null && $registered['student_id'] === $student->value &&
        $registered['section_id'] === $section->value && $registered['status'] === $facts['status'] &&
        $registered['term_id'] === ($root->registrationTermId() ?? $facts['term_id']);
        if (!$removed && !$sameScope) {
            throw new CorruptHistoryException();
        }
        $offered = null;
        $version = $root->sectionVersion();
        if ($version !== null) {
            $definition = $this->sections->definitions(
                [['section_id' => $section->value, 'version_id' => $version]],
                $budget
            )[$section->value][$version];
            $offered = $definition['continuing_education']['ceu_milli'] ?? null;
        }
        return [$id, $root, $facts, $offered];
    }

    private function fresh(
        AcademicCredit $root,
        array $facts,
        ?int $offered,
        int $version,
        int $position,
        ?int $milli,
        string $effectiveOn,
        ?string $completedOn,
    ): array {
        if ($facts['version'] !== $version || $root->playhead() !== $position) {
            throw new ConcurrentModificationException(trans('The academic record changed. Reload earned CEUs.'));
        }
        if (!$this->eligible($root, $offered)) {
            throw new ValidationException([['field' => 'earned_milli', 'message' =>
                trans(
                    'Earned CEUs need a verified active or withdrawn institutional attempt, '
                    . 'a published offering declaration, completion evidence and no pending grade correction.',
                )]]);
        }
        $completion = $completedOn;
        if ($completion === null) {
            throw new ValidationException([['field' => 'completed_on', 'message' =>
                trans('Enter the independently verified CEU completion date.')]]);
        }
        $award = new EarnedCeus(['earned_milli' => $milli, 'offered_milli' => $offered,
            'section_version_id' => $root->sectionVersion(), 'completed_on' => $completion])->facts;
        SectionDefinitionV1::assertDate($effectiveOn);
        if ($effectiveOn < $award['completed_on'] || $effectiveOn > $this->clock->now()->format('Y-m-d')) {
            throw new ValidationException([['field' => 'effective_on', 'message' =>
                trans('Use an effective date from actual completion through today.')]]);
        }
        if (($root->earnedCeus() === null && $milli === null) || EarnedCeus::same($root->earnedCeus(), $award)) {
            throw new ValidationException([['field' => 'earned_milli', 'message' => trans(
                'Change the recorded earned CEUs.',
            )]]);
        }
        return $award;
    }

    private function eligible(AcademicCredit $root, ?int $offered): bool
    {
        return $offered !== null && $root->canDecideEarnedCeus();
    }

    private function summary(AcademicCredit $root, array $facts, ?int $offered): array
    {
        return ['course_code' => $facts['course_code'], 'course_title' => $facts['course_title'],
            'withdrawn' => $facts['status'] === 'W',
            'offered_milli' => $offered, 'earned_ceus' => $root->earnedCeus(), 'completed_on' => $root->completedOn(),
            'expected_version' => $facts['version'], 'expected_position' => $root->playhead()];
    }

    private function intent(EntityId $student, EntityId $section, EntityId $enrollment, array $command): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            ksort($value);
            return array_map($normalize, $value);
        };
        return hash('sha256', json_encode($normalize([$student->value, $section->value,
            $enrollment->value, $command]), JSON_THROW_ON_ERROR));
    }

    private function reason(string $reason, bool $acknowledged): string
    {
        if (
            !$acknowledged || trim($reason) === '' || mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([['field' => 'reason', 'message' =>
                trans(
                    'Confirm the institutional CEU evidence and explain the decision in at '
                    . 'most 1,000 characters.',
                )]]);
        }
        return trim($reason);
    }

    private function lock(EntityId $student, EntityId $section, EntityId $actor, bool $write): void
    {
        $this->records->lockStudent($student);
        $this->records->lockSection($section);
        $this->sameActor($actor, $write);
    }

    private function authorize(bool $write, bool $closed = false): EntityId
    {
        $actor = $this->access->requirePermission('sis:student:read');
        if ($write && $this->access->requirePermission(EarnedCeus::PERMISSION)->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        if ($closed && $this->access->requirePermission('sis:gradebook:closed')->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function sameActor(EntityId $actor, bool $write, bool $closed = false): void
    {
        if ($this->authorize($write, $closed)->value !== $actor->value) {
            throw new AccessDeniedException();
        }
    }

    private function stream(EntityId $credit): StreamReference
    {
        return new StreamReference(new AcademicCreditId($credit->value), 'academic_credit');
    }
}
