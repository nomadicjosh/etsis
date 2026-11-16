<?php

declare(strict_types=1);

namespace Domain\Scheduling;

enum BookingUpdateKind: string
{
    case Approved = 'approved';
    case Cancelled = 'cancelled';
    case MeetingCancelled = 'meeting_cancelled';
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Request approved',
            self::Cancelled => 'Booking cancelled',
            self::MeetingCancelled => 'Meeting cancelled',
            self::Rescheduled => 'Meeting rescheduled',
        };
    }
}
