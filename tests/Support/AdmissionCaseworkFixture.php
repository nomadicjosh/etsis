<?php

declare(strict_types=1);

namespace Tests\Support;

use CampusFixture;

final class AdmissionCaseworkFixture
{
    public CampusFixture $campus;
    public \Domain\Admissions\Service\AdmissionCaseworkService $service;
    public \Domain\Shared\ValueObject\EntityId $application;

    public function __construct(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Admissions\Repository\AdmissionsRepository $admissions = null
    ) {
        $c = $this->campus = new CampusFixture();
        $f = $c->sis;
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Case applicant');
        $this->application = $f->legacyApplication($person->id, $f->program, $f->term)->id;
        $this->service = new \Domain\Admissions\Service\AdmissionCaseworkService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository($f->sql, $c->cipher),
            $admissions ?? $f->admissions,
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $f->sql,
                $f->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            ),
            $f->access,
            new \Infrastructure\Persistence\SqlActorDirectory($f->sql),
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }
}
