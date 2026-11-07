<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\BillingRule;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Shared\ValueObject\EntityId;

interface BillingRuleRepository
{
    public function save(BillingRule $rule): void;
    public function get(BillingRuleId $id): BillingRule;
    /** @return list<BillingRule> */
    public function forTerm(EntityId $term): array;
}
