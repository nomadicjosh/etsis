<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\BillingRule;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

interface BillingRuleRepository
{
    public function save(BillingRule $rule, EventContext $context): void;
    public function get(BillingRuleId $id, ?HistoryReadBudget $budget = null): BillingRule;
    /** @param list<EntityId>|null $programs
     * @return list<BillingRule> */
    public function forTerm(EntityId $term, ?HistoryReadBudget $budget = null, ?array $programs = null): array;
}
