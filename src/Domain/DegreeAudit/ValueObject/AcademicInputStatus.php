<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

enum AcademicInputStatus: string
{
    case Verified = 'verified';
    case HistoryGaps = 'history_gaps';
    case Limited = 'limited';
    case Inconsistent = 'inconsistent';
}
