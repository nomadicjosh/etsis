<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\BillingRule;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Repository\BillingRuleRepository;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class TermBillingService
{
    public function __construct(
        private BillingRuleRepository $rules,
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
        BillingBasis $basis = BillingBasis::Term,
    ): BillingRule {
        $actor = $this->access->requirePermission("sis:finance:post");
        $reason = $this->commands->reason($reason);
        $id = new BillingRuleId($operation->value);
        $candidate = BillingRule::define(
            $id,
            $term,
            $program,
            $name,
            $kind,
            $amount,
            $actor,
            $basis,
        );
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $candidate,
            $reason,
            $actor,
        ): BillingRule {
            try {
                $existing = $this->rules->get($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            $this->commands->assertActor($actor, "sis:finance:post");
            if ($existing !== null) {
                if (
                    $existing->termId()->value !== $candidate->termId()->value ||
                    $existing->programId()?->value !== $candidate->programId()?->value ||
                    $existing->basis() !== $candidate->basis() ||
                    $existing->name() !== $candidate->name() ||
                    $existing->kind() !== $candidate->kind() ||
                    $existing->amount()->minor !== $candidate->amount()->minor ||
                    $existing->amount()->currency !== $candidate->amount()->currency
                ) {
                    throw new ConcurrentModificationException(
                        "This operation already defined a different billing rule.",
                    );
                }
                return $existing;
            }
            $this->rules->save($candidate, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, "billing.rule_defined", $operation);
            $this->commands->assertActor($actor, "sis:finance:post");
            return $candidate;
        });
    }

    public function get(BillingRuleId $id): BillingRule
    {
        $actor = $this->access->requirePermission("sis:finance:read");
        $rule = $this->rules->get($id);
        $this->commands->assertActor($actor, "sis:finance:read");
        return $rule;
    }

    /** @return array{status:string} */
    public function retire(
        BillingRuleId $id,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:finance:post");
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use ($id, $position, $reason, $actor): array {
            $rule = $this->rules->get($id);
            $this->commands->assertActor($actor, "sis:finance:post");
            if ($rule->isRetired()) {
                return ["status" => "already_recorded"];
            }
            $this->commands->assertPosition($rule->playhead(), $position);
            $rule->retire($actor);
            $operation = new EntityId((string) $id);
            $this->rules->save($rule, $this->commands->context($actor, $operation, $reason));
            $this->audit->record($actor, "billing.rule_retired", $operation);
            $this->commands->assertActor($actor, "sis:finance:post");
            return ["status" => "retired"];
        });
    }
}
