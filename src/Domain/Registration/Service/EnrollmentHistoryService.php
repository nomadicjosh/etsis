<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Curated registrar history, including restricted records removed during early add/drop. */
final readonly class EnrollmentHistoryService
{
    public function __construct(
        private EnrollmentHistoryRepository $records,
        private RegistrationRepository $registration,
        private EnrollmentLedger $ledger,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }
    /** @return array<string,mixed> */
    public function student(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($student, $after, $actor): array {
            $this->registration->lockStudent($student);
            if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
                throw new AccessDeniedException("The registration actor changed.");
            }
            if (
                $after !== null &&
                ($this->records->mapping($after)["student_id"] ?? null) !== $student->value
            ) {
                throw new RecordNotFoundException("Registration history cursor not found.");
            }
            $rows = $this->records->forStudent($student, $after, 21);
            $items = [];
            foreach (array_slice($rows, 0, 20) as $r) {
                $items[] = $this->ledger->summary(new EntityId($r["id"]));
            }
            $this->audit->record($actor, "registration.history_read", $student);
            return ["items" => $items, "next_after" => count($rows) > 20 ? $rows[19]["id"] : null];
        });
    }
}
