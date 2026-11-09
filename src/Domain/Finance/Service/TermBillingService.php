<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\BillingRule;
use Domain\Finance\Repository\BillingRuleRepository;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\RegistrationBilling;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class TermBillingService
{
    public function __construct(
        private BillingRuleRepository $rules,
        private RegistrationBilling $billing,
        private RegistrationRepository $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    public function define(EntityId $term, ?EntityId $program, string $name, string $kind, Money $amount): BillingRule
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $rule = BillingRule::define(new BillingRuleId(), $term, $program, $name, $kind, $amount, $actor);
        $this->rules->save($rule);

        return $rule;
    }

    public function get(BillingRuleId $id): BillingRule
    {
        $this->access->requirePermission('sis:finance:read');

        return $this->rules->get($id);
    }

    public function retire(BillingRuleId $id): void
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $rule = $this->rules->get($id);
        $rule->retire($actor);
        $this->rules->save($rule);
    }

    /** @param list<EntityId> $students */
    public function generate(EntityId $term, array $students): void
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        if (count($students) < 1 || count($students) > 100) {
            throw new InvalidArgumentException('A billing batch must include between one and 100 students.');
        }
        usort($students, static fn (EntityId $a, EntityId $b): int => strcmp($a->value, $b->value));
        $this->transactions->run(function () use ($term, $students, $actor): void {
            foreach ($students as $student) {
                $this->registration->lockStudent($student);
            }
            // Read program eligibility only after acquiring the entire ordered lock set.
            foreach ($students as $student) {
                $this->billing->charge($student, $term, EntityId::generate(), new Money(0, 'USD'), $actor);
                $this->audit->record($actor, 'billing.generated', $student);
            }
        });
    }
}
