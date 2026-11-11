<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

enum TransferGpaPolicy: string
{
    case Exclude = "exclude";
    case IncludeEligible = "include_eligible";
}
