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
