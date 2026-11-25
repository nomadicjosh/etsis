<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Advising\AppointmentTime;
use Domain\Advising\AvailabilityPattern;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Advising\Service\AdvisingAvailabilityService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdvisorRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingAppointmentRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingAvailabilityRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingAvailabilitySeriesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use SisFixture;

/** Ordinary production services, with private fictional command data and no fixture creation in workers. */
final readonly class AdvisingRecoveryServices
{
    public function __construct(private SisFixture $sis)
    {
    }

    public function appointments(?AuditTrail $audit = null): AdvisingAppointmentService
    {
        $s = $this->sis;
        return new AdvisingAppointmentService(
            new SqlAdvisingAppointmentRepository($s->sql, $s->dropCipher),
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
            new SqlAdvisingAvailabilityRepository($s->sql, $s->dropCipher),
            new \Domain\Advising\Service\BookingPolicyService(
                new \Infrastructure\Persistence\Repository\SqlBookingPolicyRepository($s->sql, $s->dropCipher),
                $s->access,
                new SqlAccessRepository($s->sql),
                $s->transactions,
                $audit ?? $s->audit,
                $s->clock,
                new ReviewProof($s->dropCipher, $s->clock),
            ),
            new \Domain\Advising\Service\StudentClassCalendarService(
                new \Infrastructure\Persistence\Repository\SqlAdvisingClassScheduleRepository($s->sql),
                new \Domain\Registration\Service\PublishedSectionMeetingPlanReader(
                    new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                        $s->sql,
                        $s->bookingSections()
                    ),
                    \EventStoreFixture::history($s->connection->queryBuilder())
                ),
                new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($s->sql)
            ),
            new \Domain\Advising\Service\AdvisorTeachingCalendarService(
                new \Infrastructure\Persistence\Repository\SqlAdvisingClassScheduleRepository($s->sql),
                new \Domain\Registration\Service\PublishedSectionMeetingPlanReader(
                    new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                        $s->sql,
                        $s->bookingSections()
                    ),
                    \EventStoreFixture::history($s->connection->queryBuilder())
                ),
                \EventStoreFixture::history($s->connection->queryBuilder())
            ),
        );
    }

    public function availability(?AuditTrail $audit = null): AdvisingAvailabilityService
    {
        $s = $this->sis;
        return new AdvisingAvailabilityService(
            new SqlAdvisingAvailabilityRepository($s->sql, $s->dropCipher),
            new SqlAdvisorRepository($s->sql),
            $s->admissions,
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new SqlAdvisingAvailabilitySeriesRepository($s->sql, $s->dropCipher),
            new ReviewProof($s->dropCipher, $s->clock),
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
            new ReviewProof($s->dropCipher, $s->clock)
        );
    }

    public function meetingPlans(?AuditTrail $audit = null): \Domain\Registration\Service\SectionMeetingPlanService
    {
        $s = $this->sis;
        $history = \EventStoreFixture::history($s->connection->queryBuilder());
        $plans = new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
            $s->sql,
            $s->bookingSections()
        );
        $reader = new \Domain\Registration\Service\PublishedSectionMeetingPlanReader($plans, $history);
        return new \Domain\Registration\Service\SectionMeetingPlanService(
            $plans,
            $history,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
            $reader,
            new \Domain\Registration\Service\FacultyScheduleService(
                new \Infrastructure\Persistence\Repository\SqlFacultyScheduleRepository($s->sql),
                new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($s->sql),
                $history,
                $reader
            )
        );
    }

    public function run(string $action, #[\SensitiveParameter] array $command, ?AuditTrail $audit = null): array
    {
        $c = $command;
        return match ($action) {
            'advising_policy_save' => $this->policies($audit)->save(new EntityId($c['id']), $c['rules'],
                $c['version'], $c['reason'], $c['review_token']),
            'advising_class_publish' => $this->meetingPlans($audit)->publish(new EntityId($c['section']), $c['token']),
            'advising_schedule' => $this->appointments($audit)->schedule(
                new EntityId($c['student']),
                new EntityId($c['pursuit']), $c['channel'], new EntityId($c['id']),
                new EntityId($c['host']), $c['start'], $c['end'], $c['timezone'], $c['message'],
            ),
            'advising_act' => $this->appointments($audit)->act(
                new EntityId($c['student']),
                new EntityId($c['pursuit']), $c['channel'], new EntityId($c['id']),
                new EntityId($c['operation']), $c['action'], $c['version'],
                $c['time'] === null ? null : new AppointmentTime(
                    $c['time']['start'], $c['time']['end'], $c['time']['timezone']
                ),
                $c['message'],
            ),
            'advising_book' => $this->appointments($audit)->book(
                new EntityId($c['student']),
                new EntityId($c['pursuit']), new EntityId($c['id']),
                new EntityId($c['window']), $c['version'], $c['start'],
            ),
            'advising_window_create' => $this->availability($audit)->create(
                new EntityId($c['host']),
                $c['channel'], new EntityId($c['id']), $c['start'], $c['end'],
                $c['timezone'], $c['minutes'], $c['message'],
            ),
            'advising_window_close' => $this->availability($audit)->close(
                new EntityId($c['host']),
                $c['channel'], new EntityId($c['id']), $c['version'], $c['message'],
            ),
            'advising_series_create' => $this->availability($audit)->createSeries(
                new EntityId($c['host']),
                $c['channel'], new EntityId($c['review']['series_id']),
                new AvailabilityPattern($c['review']['pattern']), $c['review']['slot_minutes'], $c['review']['message'],
                $c['review']['window_ids'], $c['review']['revision'], $c['review']['review_token'],
            ),
            'advising_series_close' => $this->availability($audit)->closeSeries(
                new EntityId($c['host']),
                $c['channel'], new EntityId($c['review']['series_id']), $c['review']['version'],
                $c['review']['window_ids'], $c['review']['reason'], $c['review']['revision'],
                $c['review']['review_token'],
            ),
            default => throw new \LogicException('Use a supported fictional advising recovery command.'),
        };
    }
}
