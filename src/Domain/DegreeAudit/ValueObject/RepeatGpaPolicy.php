<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

enum RepeatGpaPolicy: string
{
    case AllEligible = "all_eligible";
    case HighestGrade = "highest_grade";
    case LatestEligible = "latest_eligible";
}
