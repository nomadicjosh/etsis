<?php

declare(strict_types=1);

namespace Domain\Admissions\Enum;

enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Matriculated = 'matriculated';
}
