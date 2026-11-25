<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Advising\Service\AdvisingAvailabilityService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdvisorRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingAvailabilityRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;

final class AdvisingAvailabilityFixture
{
    public AdvisingAppointmentsFixture $appointments;
    public SqlAdvisingAvailabilityRepository $records;
    public function __construct(?\SisFixture $sis = null)
    {
        require_once __DIR__ . '/AdvisingAppointmentsFixture.php';
        $this->appointments = $f = new AdvisingAppointmentsFixture($sis);
        $this->records = new SqlAdvisingAvailabilityRepository($f->sis->sql, $f->sis->dropCipher);
    }
    public function service(?AuditTrail $audit = null): AdvisingAvailabilityService
    {
        $s = $this->appointments->sis;
        return new AdvisingAvailabilityService(
            $this->records,
            new SqlAdvisorRepository($s->sql),
            $s->admissions,
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAdvisingAvailabilitySeriesRepository($s->sql, $s->dropCipher),
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
        );
    }
    public function offer(?EntityId $id = null, string $channel = 'staff', string $date = '2026-08-11'): array
    {
        $f = $this->appointments;
        return $this->service()->create(
            $f->host,
            $channel,
            $id ?? EntityId::generate(),
            $date . 'T10:00',
            $date . 'T12:00',
            $f->sis->clock->now()->getTimezone()->getName(),
            30,
            'Fictional office meeting instructions.'
        );
    }
}
