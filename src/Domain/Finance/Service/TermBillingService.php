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
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
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
        private AuditTrail $audit,
        private FinancialCommandPolicy $commands,
    ) {
    }

    public function define(
        EntityId $operation,
        EntityId $term,
        ?EntityId $program,
        string $name,
        string $kind,
        Money $amount,
        #[\SensitiveParameter] string $reason,
    ): BillingRule {
        $actor = $this->access->requirePermission('sis:finance:post');
        $reason = $this->commands->reason($reason);
        $id = new BillingRuleId($operation->value);
        $candidate = BillingRule::define(
            $id,
            $term,
            $program,
            $name,
            $kind,
            $amount,
            $actor
        );
        return $this->transactions->run(function () use ($id, $operation, $candidate, $reason, $actor): BillingRule {
            try {
                $existing = $this->rules->get($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
            if ($existing !== null) {
                if (
                    $existing->termId()->value !== $candidate->termId()->value
                    || $existing->programId()?->value !== $candidate->programId()?->value
                    || $existing->name() !== $candidate->name() || $existing->kind() !== $candidate->kind()
                    || $existing->amount()->minor !== $candidate->amount()->minor
                    || $existing->amount()->currency !== $candidate->amount()->currency
                ) {
                    throw new ConcurrentModificationException(
                        'This operation already defined a different billing rule.'
                    );
                }
                return $existing;
            }
            $this->rules->save($candidate, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, 'billing.rule_defined', $operation);
            $this->commands->assertActor($actor, 'sis:finance:post');
            return $candidate;
        });
    }

    public function get(BillingRuleId $id): BillingRule
    {
        $actor = $this->access->requirePermission('sis:finance:read');
        $rule = $this->rules->get($id);
        $this->commands->assertActor($actor, 'sis:finance:read');
        return $rule;
    }

    /** @return array{status:string} */
    public function retire(BillingRuleId $id, int $position, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use ($id, $position, $reason, $actor): array {
            $rule = $this->rules->get($id);
            $this->commands->assertActor($actor, 'sis:finance:post');
            if ($rule->isRetired()) {
                return ['status' => 'already_recorded'];
            }
            $this->commands->assertPosition($rule->playhead(), $position);
            $rule->retire($actor);
            $operation = new EntityId((string) $id);
            $this->rules->save($rule, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, 'billing.rule_retired', $operation);
            $this->commands->assertActor($actor, 'sis:finance:post');
            return ['status' => 'retired'];
        });
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
            $batch = EntityId::generate();
            foreach ($students as $student) {
                $this->registration->lockStudent($student);
            }
            // Read program eligibility only after acquiring the entire ordered lock set.
            foreach ($students as $student) {
                $this->commands->assertActor($actor, 'sis:finance:post');
                $this->billing->charge(
                    $student,
                    $term,
                    EntityId::generate(),
                    new Money(0, 'USD'),
                    $this->commands->context(
                        $actor,
                        $batch,
                        'Account charges applied by an authorized term billing batch.',
                        $term
                    )
                );
                $this->audit->record($actor, 'billing.generated', $student);
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
        });
    }
}
