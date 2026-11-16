<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\EventContext;

interface RegistrationBilling
{
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
        EventContext $context,
    ): void;
}
