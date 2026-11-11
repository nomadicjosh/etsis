<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Institutional chronology choice; academic-period ordering is the default for new selections. */
enum RepeatAttemptOrder: string
{
    case AcademicPeriod = "academic_period";
    case CompletionDate = "completion_date";
}
