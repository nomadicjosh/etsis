<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Admissions\Service\AdmissionChangeRequestService;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdmissionChangeRequestRepository;

final class AdmissionChangeRequestFixture
{
    public readonly \SisFixture $sis;
    public readonly EntityId $owner;
    public readonly EntityId $application;
    public readonly EntityId $manager;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = new \SisFixture();
        $s = $this->sis;
        $this->manager = $s->access->actor;
        $this->owner = $s->user();
        $person = $s->legacyPerson($this->owner, 'Prospect', 'Fictional');
        require_once __DIR__ . '/AcademicCalendarFixture.php';
        $calendar = new \AcademicCalendarFixture($s);
        $calendar->type();
        $year = $calendar->year(2027);
        $term = new EntityId($s->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $this->application = $s->submissionService()->apply(
            EntityId::generate(),
            $person->id,
            $s->program,
            $term
        )->id;
    }

    public function service(?\Domain\Audit\Repository\AuditTrail $audit = null): AdmissionChangeRequestService
    {
        $s = $this->sis;
        return new AdmissionChangeRequestService(
            new SqlAdmissionChangeRequestRepository($s->sql, $s->dropCipher),
            $s->admissions,
            $s->decisionLedger(),
            $s->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($s->sql)
        );
    }

    public function owner(): AdmissionChangeRequestService
    {
        $this->sis->access->actor = $this->owner;
        return $this->service();
    }

    public function programCorrections(): \Domain\Admissions\Service\AdmissionProgramCorrectionService
    {
        $s = $this->sis;
        return new \Domain\Admissions\Service\AdmissionProgramCorrectionService(
            $s->admissions,
            $s->decisionLedger(),
            $s->applicationContext(),
            $s->entryTerms(),
            $s->withdrawalRepository(),
            $s->applicationRevisions(),
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    public function casework(): \Domain\Admissions\Service\AdmissionCaseworkService
    {
        $s = $this->sis;
        $people = new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
            $s->sql,
            $s->admissions,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
        );
        return new \Domain\Admissions\Service\AdmissionCaseworkService(
            new \Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository($s->sql, $s->dropCipher),
            $s->admissions,
            $people,
            $s->access,
            new \Infrastructure\Persistence\SqlActorDirectory($s->sql),
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository($s->sql),
            $s->admissionPolicy()
        );
    }

    public function facts(): array
    {
        $s = $this->sis;
        $tables = ['sis_applications', 'sis_persons', 'sis_students', 'sis_student_programs',
            'sis_admission_cases', 'sis_application_intake', 'sis_form_submissions'];
        return [\EventStoreFixture::historyDigest($s->sql), ...array_map($s->sql->rows(...), $tables)];
    }
}
