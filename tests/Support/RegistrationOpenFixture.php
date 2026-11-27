<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Service\RegistrationOpenService;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationOpenRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationWindowRepository;

final class RegistrationOpenFixture
{
    public \SisFixture $sis;
    public EntityId $owner;
    public EntityId $person;
    public EntityId $student;
    public SqlRegistrationOpenRepository $records;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $s = $this->sis = new \SisFixture();
        $this->owner = $s->access->actor;
        $student = $s->student($this->owner);
        $this->person = $student->personId;
        $this->student = $student->id;
        $this->records = new SqlRegistrationOpenRepository($s->sql);
    }

    public function publish(?EntityId $term = null, string $opens = '-1 day', string $closes = '+1 day'): array
    {
        $s = $this->sis;
        $s->access->staff = true;
        $term ??= $s->term;
        $review = $s->windowPolicies()->review($term, new RegistrationWindow(new DateRange(
            $s->clock->now()->modify($opens),
            $s->clock->now()->modify($closes)
        ), 5), $s->windowPolicies()->show($term)['version'], 'Fictional registration availability.');
        $receipt = $s->windowPolicies()->publish($term, $review['review_token']);
        $s->access->staff = false;
        return $receipt;
    }

    public function service(?AuditTrail $audit = null): RegistrationOpenService
    {
        $s = $this->sis;
        return new RegistrationOpenService(
            $this->records,
            new SqlRegistrationWindowRepository($s->sql),
            $s->windowPolicies(),
            new SqlAccessRepository($s->sql),
            $s->admissions,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock
        );
    }

    public function audit(\Closure $during): AuditTrail
    {
        return new class ($this->sis->audit, $during) implements AuditTrail {
            public function __construct(private AuditTrail $inner, private \Closure $during)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->inner->record($actor, $action, $subject);
                ($this->during)();
            }
            public function forSubject(EntityId $id): array
            {
                return $this->inner->forSubject($id);
            }
        };
    }
}
