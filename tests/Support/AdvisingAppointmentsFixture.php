<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Advising\AppointmentTime;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingAppointmentRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use SisFixture;

final class AdvisingAppointmentsFixture
{
    public SisFixture $sis;
    public EntityId $student;
    public EntityId $pursuit;
    public EntityId $owner;
    public EntityId $staff;
    public EntityId $host;
    public EntityId $advisor;
    public SqlAdvisingAppointmentRepository $records;

    public function __construct(?SisFixture $sis = null)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = $sis ?? new SisFixture();
        $this->staff = $s->access->actor;
        $this->owner = $s->user();
        $this->student = $s->student($this->owner)->id;
        $this->pursuit = new EntityId($s->sql->rows(
            'sis_student_programs',
            ['student_id' => $this->student->value],
            1
        )[0]['id']);
        $this->advisor = $s->user();
        $this->host = $s->legacyPerson($this->advisor, 'Fictional', 'Advisor')->id;
        $s->sql->insert('sis_employees', ['id' => EntityId::generate()->value, 'person_id' => $this->host->value,
            'job_title' => 'Fictional advisor', 'active' => 1]);
        $this->assign();
        $this->records = new SqlAdvisingAppointmentRepository($s->sql, $s->dropCipher);
    }

    public function assign(string $start = '2026-01-01', ?string $end = null): void
    {
        $service = $this->sis->advisors();
        $view = $service->view($this->student, $this->pursuit);
        $review = $service->review($this->student, $this->pursuit, [[
            'id' => EntityId::generate()->value, 'advisor_person_id' => $this->host->value,
            'role' => 'primary', 'starts_on' => $start, 'ends_on' => $end,
        ]], $view['revision'], 'Fictional advising assignment.');
        $service->commit(
            $this->student,
            $this->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
    }

    public function service(?AuditTrail $audit = null): AdvisingAppointmentService
    {
        $s = $this->sis;
        return new AdvisingAppointmentService(
            $this->records,
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            $s->programStatusLedger(),
            $s->primaryProgramLedger(),
            $s->advisors(),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAdvisingAvailabilityRepository($s->sql, $s->dropCipher),
            $this->policies(),
            $this->calendar(),
            $this->teaching(),
        );
    }

    public function policies(?AuditTrail $audit = null): \Domain\Advising\Service\BookingPolicyService
    {
        $s = $this->sis;
        return new \Domain\Advising\Service\BookingPolicyService(
            new \Infrastructure\Persistence\Repository\SqlBookingPolicyRepository($s->sql, $s->dropCipher),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
        );
    }

    public function calendar(
        ?\Domain\Advising\Repository\AdvisingClassScheduleRepository $records = null
    ): \Domain\Advising\Service\StudentClassCalendarService {
        $s = $this->sis;
        return new \Domain\Advising\Service\StudentClassCalendarService(
            $records ?? new \Infrastructure\Persistence\Repository\SqlAdvisingClassScheduleRepository($s->sql),
            new \Domain\Registration\Service\PublishedSectionMeetingPlanReader(
                new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                    $s->sql,
                    $s->bookingSections()
                ),
                \EventStoreFixture::history($s->connection->queryBuilder())
            ),
            new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($s->sql)
        );
    }

    public function teaching(): \Domain\Advising\Service\AdvisorTeachingCalendarService
    {
        $s = $this->sis;
        return new \Domain\Advising\Service\AdvisorTeachingCalendarService(
            new \Infrastructure\Persistence\Repository\SqlAdvisingClassScheduleRepository($s->sql),
            new \Domain\Registration\Service\PublishedSectionMeetingPlanReader(
                new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                    $s->sql,
                    $s->bookingSections()
                ),
                \EventStoreFixture::history($s->connection->queryBuilder())
            ),
            \EventStoreFixture::history($s->connection->queryBuilder())
        );
    }

    public function audit(\Closure $during): AuditTrail
    {
        return new class ($this->sis->audit, $during) implements AuditTrail {
            public function __construct(private AuditTrail $inner, private \Closure $during)
            {
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->inner->forSubject($subject);
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->inner->record($actor, $action, $subject);
                ($this->during)();
            }
        };
    }

    public function time(string $start = '2026-08-11T10:00', string $end = '2026-08-11T10:30'): AppointmentTime
    {
        return new AppointmentTime($start, $end, $this->sis->clock->now()->getTimezone()->getName());
    }

    public function schedule(?EntityId $id = null, string $channel = 'staff', ?AppointmentTime $time = null): array
    {
        $t = $time ?? $this->time();
        return $this->service()->schedule(
            $this->student,
            $this->pursuit,
            $channel,
            $id ?? EntityId::generate(),
            $this->host,
            $t->start,
            $t->end,
            $t->timezone,
            'Fictional meeting instructions.'
        );
    }

    public function act(
        EntityId $id,
        string $action,
        int $version = 0,
        string $channel = 'staff',
        ?AppointmentTime $time = null,
    ): array {
        return $this->service()->act(
            $this->student,
            $this->pursuit,
            $channel,
            $id,
            EntityId::generate(),
            $action,
            $version,
            $time,
            'Fictional appointment change.'
        );
    }
}
