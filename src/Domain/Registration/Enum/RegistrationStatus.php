<?php

declare(strict_types=1);

namespace Domain\Registration\Enum;

enum RegistrationStatus: string
{
    case StaffRegistered = 'A';
    case SelfRegistered = 'N';
    case Dropped = 'D';
    case Withdrawn = 'W';
    case Cancelled = 'C';

    public function isEnrolled(): bool
    {
        return $this === self::StaffRegistered || $this === self::SelfRegistered;
    }
}
