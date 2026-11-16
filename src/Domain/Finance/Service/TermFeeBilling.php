<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Finance\BillingRule;
use Domain\Finance\ValueObject\TermFeeQuote;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Authorized callers own ordered student/person locks and the surrounding transaction. */
interface TermFeeBilling
{
    /** @param list<EntityId> $programs
     * @return list<BillingRule> */
    public function rules(EntityId $term, HistoryReadBudget $budget, array $programs): array;

    /** @param list<BillingRule> $rules */
    public function quote(EntityId $student, EntityId $term, array $rules, HistoryReadBudget $budget): TermFeeQuote;

    /** @return array{student_id:string,account_id:?string,created:bool,posted:int,position:?int} */
    public function apply(TermFeeQuote $quote, EventContext $context): array;
}
