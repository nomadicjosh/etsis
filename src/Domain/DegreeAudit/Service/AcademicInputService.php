<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;

/** Checks surviving routing sources under a student lock. It neither evaluates nor repairs records. */
final readonly class AcademicInputService
{
    public function __construct(
        private AcademicInputVerifier $verifier,
        private StudentRecordRepository $students,
        private StudentRequirementsService $requirements,
        private UnitOfWork $transactions,
        private \Domain\AcademicRecord\Service\WorkPursuitService $pursuits,
    ) {
    }

    /** The optional budget is an internal shared work allowance, never an HTTP parameter. */
    public function collect(
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget = null,
    ): AcademicInputSet {
        $this->requirements->authorizeRead($student, $assignment);
        try {
            return $this->transactions->run(function () use (
                $student,
                $assignment,
                $budget,
            ): AcademicInputSet {
                $this->students->lock($student);
                $this->requirements->authorizeRead($student, $assignment);
                $budget ??= new HistoryReadBudget();
                $result = $this->pursuits->forPursuit(
                    $this->verifier->verify($student, $budget),
                    $student,
                    $assignment,
                    $budget
                );
                $this->requirements->authorizeRead($student, $assignment);
                return $result;
            });
        } catch (HistoryReadLimitException) {
            $status = AcademicInputStatus::Limited;
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $status = AcademicInputStatus::Inconsistent;
        }
        // A failed nested history read rolls back its transaction; no partial snapshot escapes it.
        $this->requirements->authorizeRead($student, $assignment);
        return new AcademicInputSet($status);
    }
}
