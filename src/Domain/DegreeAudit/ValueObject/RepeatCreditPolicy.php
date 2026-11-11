<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

enum RepeatCreditPolicy: string
{
    case HighestEarned = "highest_earned";
    case AllEligible = "all_eligible";
}
