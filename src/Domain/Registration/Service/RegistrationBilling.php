<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

interface RegistrationBilling
{
    public function charge(EntityId $student, EntityId $term, EntityId $operation, Money $fee, EntityId $actor): void;
}
