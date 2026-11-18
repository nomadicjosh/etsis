<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\EventContext;

interface RegistrationBilling extends \Domain\Finance\Service\TermFeeBilling
{
    /** @param list<\Domain\Finance\BillingRule>|null $rules Shared verified rule view for one selected group. */
    public function registrationQuote(
        EntityId $student,
        EntityId $term,
        Money $fee,
        int $creditsMilli,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        ?array $rules = null,
    ): \Domain\Finance\ValueObject\RegistrationChargeQuote;
    public function reverseCourseCharge(
        EntityId $student,
        EntityId $term,
        EntityId $enrollment,
        EventContext $context,
    ): bool;

    public function charge(
        EntityId $student,
        EntityId $term,
        EntityId $operation,
        Money $fee,
        int $creditsMilli,
        EventContext $context,
        ?\Domain\Finance\ValueObject\RegistrationChargeQuote $quote = null,
    ): void;
}
