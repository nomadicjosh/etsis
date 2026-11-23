<?php

declare(strict_types=1);

namespace Tests\Support;

use CampusFixture;

final class AdmissionCaseworkFixture
{
    public CampusFixture $campus;
    public \Domain\Admissions\Service\AdmissionCaseworkService $service;
    public \Domain\Admissions\Service\AdmissionUpdatesService $updates;
    public \Domain\Shared\ValueObject\EntityId $application;

    public function documents(
        \Domain\Admissions\Service\AdmissionDocumentStorage $storage,
        ?\Domain\Admissions\Service\AdmissionDocumentPolicy $policy = null,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Admissions\Service\AdmissionDocumentService {
        $s = $this->campus->sis;
        return new \Domain\Admissions\Service\AdmissionDocumentService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository($s->sql),
            $s->admissions,
            $storage,
            $policy ?? new \Domain\Admissions\Service\AdmissionDocumentPolicy(true, 365, true),
            $this->campus->cipher,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $s->decisionLedger(),
            $this->documentHolds(),
            $this->documentRetention($storage, $policy),
        );
    }

    public function documentRetention(
        ?\Domain\Admissions\Service\AdmissionDocumentStorage $storage = null,
        ?\Domain\Admissions\Service\AdmissionDocumentPolicy $policy = null,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Admissions\Service\AdmissionRetentionService {
        $s = $this->campus->sis;
        $storage ??= new \Infrastructure\Admissions\PrivateAdmissionDocumentStorage($this->campus->cipher);
        return new \Domain\Admissions\Service\AdmissionRetentionService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionRetentionRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository($s->sql),
            $s->admissions,
            new \Domain\Admissions\Service\AdmissionDocumentContent($this->campus->cipher, $storage),
            $policy ?? new \Domain\Admissions\Service\AdmissionDocumentPolicy(true, 365, true),
            $this->campus->cipher,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($this->campus->cipher, $s->clock),
            new \Infrastructure\Persistence\SqlActorDirectory($s->sql),
        );
    }

    public function documentHolds(
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): \Domain\Admissions\Service\AdmissionDocumentHoldService {
        $s = $this->campus->sis;
        return new \Domain\Admissions\Service\AdmissionDocumentHoldService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionDocumentHoldRepository($s->sql),
            $s->admissions,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $this->campus->cipher,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($this->campus->cipher, $s->clock),
            new \Infrastructure\Persistence\SqlActorDirectory($s->sql),
        );
    }

    public function __construct(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $admissions = null,
        ?\Domain\Access\Repository\AccessRepository $permissions = null
    ) {
        $c = $this->campus = new CampusFixture();
        $f = $c->sis;
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Case applicant');
        $this->application = $f->legacyApplication($person->id, $f->program, $f->term)->id;
        $people = new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
            $f->sql,
            $f->admissions,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
        );
        $updates = new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($f->sql);
        $this->service = new \Domain\Admissions\Service\AdmissionCaseworkService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository($f->sql, $c->cipher),
            $admissions ?? $f->admissions,
            $people,
            $f->access,
            new \Infrastructure\Persistence\SqlActorDirectory($f->sql),
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
            $permissions ?? new \Infrastructure\Persistence\Repository\SqlAccessRepository($f->sql),
            $updates,
            $f->admissionPolicy(),
        );
        $this->updates = new \Domain\Admissions\Service\AdmissionUpdatesService(
            $updates,
            $people,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }
}
