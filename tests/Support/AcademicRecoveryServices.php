<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Admissions\Service\AdmissionProgramCorrectionService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Service\ReviewProof;
use Domain\Transfer\Service\TransferAwardService;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use Infrastructure\Persistence\Repository\SqlTransferAwardRepository;
use SisFixture;

/** Shared real service wiring for the isolated parent and worker; no business rules or fixture creation. */
final readonly class AcademicRecoveryServices
{
    public function __construct(private SisFixture $sis)
    {
    }

    public function programs(?AuditTrail $audit = null): AdmissionProgramCorrectionService
    {
        $s = $this->sis;
        return new AdmissionProgramCorrectionService(
            $s->admissions,
            $s->decisionLedger(),
            $s->applicationContext(),
            $s->entryTerms(),
            $s->withdrawalRepository(),
            $s->applicationRevisions(),
            new SqlApplicationFormRepository($s->sql),
            new SqlAdmissionUpdatesRepository($s->sql),
            new SqlAccessRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    public function transfers(?AuditTrail $audit = null): TransferAwardService
    {
        $s = $this->sis;
        return new TransferAwardService(
            new SqlTransferAwardRepository($s->sql, $s->gradeCatalog()),
            $s->registration,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->transferCredits(),
            new ReviewProof($s->dropCipher, $s->clock),
        );
    }
}
