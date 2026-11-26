<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Current minimized CEU records come from verified history, without exposing decision narratives. */
final readonly class CeuRecordService
{
    public function __construct(
        private AcademicInputVerifier $inputs,
        private AcademicCreditRepository $records,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
    ) {
    }

    public function record(EntityId $student): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($student, $actor): array {
            $this->records->lockStudent($student);
            $this->authorize($actor, $student);
            $budget = new HistoryReadBudget(maximumBytes: 8_388_608, maximumMilliseconds: 5000);
            $input = $this->inputs->verify($student, $budget);
            if (in_array($input->status, [AcademicInputStatus::Limited, AcademicInputStatus::Inconsistent], true)) {
                throw new CorruptHistoryException();
            }
            $result = [];
            $terms = [];
            $items = $input->items;
            usort($items, static fn (array $first, array $second): int =>
                [$first['term_id'], $first['id']] <=> [$second['term_id'], $second['id']]);
            foreach ($items as $work) {
                if (
                    !isset($work['earned_ceus']['earned_milli']) || !$work['history_recorded'] ||
                    $work['credit_type'] !== 'institutional' || !in_array($work['status'], ['A', 'N', 'W'], true)
                ) {
                    continue;
                }
                $term = $work['term_code'] ?? ($terms[$work['term_id']] ??=
                    $this->records->term(new EntityId($work['term_id']))['code']);
                $result[] = ['course_code' => $work['course_code'], 'course_title' => $work['course_title'],
                    'term_code' => $term, 'earned_ceus' => [
                        'earned_milli' => $work['earned_ceus']['earned_milli'],
                        'completed_on' => $work['earned_ceus']['completed_on'],
                    ]];
            }
            $final = $this->inputs->verify($student, $budget);
            if (
                $final->status !== $input->status || $final->items !== $input->items ||
                $final->references !== $input->references
            ) {
                throw new CorruptHistoryException();
            }
            $this->authorize($actor, $student);
            return $result;
        });
    }

    private function authorize(EntityId $actor, EntityId $student): void
    {
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:student:read')->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
    }
}
