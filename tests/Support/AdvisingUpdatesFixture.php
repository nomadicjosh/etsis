<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Advising\Service\AdvisingCheckInNotifications;
use Domain\Advising\Service\AdvisingCheckInService;
use Domain\Advising\Service\AdvisingUpdatesService;
use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingCheckInRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use SisFixture;

final class AdvisingUpdatesFixture
{
    public SisFixture $sis;
    public EntityId $student;
    public EntityId $pursuit;
    public EntityId $owner;
    public EntityId $staff;
    public EntityId $person;
    public SqlAdvisingUpdatesRepository $repository;
    public SqlAdvisingCheckInRepository $records;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = new SisFixture();
        $this->staff = $s->access->actor;
        $this->owner = $s->user();
        $student = $s->student($this->owner);
        $this->student = $student->id;
        $this->person = $student->personId;
        $row = $s->sql->rows('sis_student_programs', ['student_id' => $student->id->value], 1)[0];
        $this->pursuit = new EntityId($row['id']);
        $this->repository = new SqlAdvisingUpdatesRepository($s->sql);
        $this->records = new SqlAdvisingCheckInRepository($s->sql, $s->dropCipher);
    }

    public function checkins(?AuditTrail $audit = null): AdvisingCheckInService
    {
        $s = $this->sis;
        return new AdvisingCheckInService(
            $this->records,
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            new SqlPersonProfileRepository($s->sql),
            $s->programStatusLedger(),
            $s->primaryProgramLedger(),
            new class implements AdvisorProgressAccess {
                public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
                {
                    throw new AccessDeniedException();
                }
            },
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new AdvisingCheckInNotifications($this->repository, $s->admissions),
        );
    }

    public function updates(?AuditTrail $audit = null): AdvisingUpdatesService
    {
        $s = $this->sis;
        return new AdvisingUpdatesService(
            $this->repository,
            $this->records,
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->admissions,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }

    public function record(?EntityId $id = null): array
    {
        $this->asStaff();
        return $this->checkins()->record(
            $this->student,
            $this->pursuit,
            'staff',
            $id ?? EntityId::generate(),
            '2026-08-10',
            null,
            'Fictional student-visible next step.',
            'Fictional protected note.'
        );
    }

    public function asOwner(): void
    {
        $this->sis->access->actor = $this->owner;
        $this->sis->access->staff = false;
    }

    public function asStaff(): void
    {
        $this->sis->access->actor = $this->staff;
        $this->sis->access->staff = true;
    }
}
