<?php

declare(strict_types=1);

namespace Domain\Advising;

use Domain\Shared\Exception\ValidationException;
use JsonSerializable;

use function Codefy\Framework\Helpers\trans;

/** Request-local verified operational schedule. No class identities or times leave the appointment service. */
final readonly class ClassCalendar implements JsonSerializable
{
    /** @param list<array{0:string,1:string}> $meetings Sorted UTC start/end pairs. */
    public function __construct(
        private AppointmentTime $range,
        private string $fingerprint,
        #[\SensitiveParameter] private array $meetings
    ) {
    }

    public function range(): AppointmentTime
    {
        return $this->range;
    }

    public function permits(AppointmentTime $time): bool
    {
        if ($time->startsAt < $this->range->startsAt || $time->endsAt > $this->range->endsAt) {
            return false;
        }
        foreach ($this->meetings as [$start, $end]) {
            if ($start >= $time->endsAt) {
                break;
            }
            if ($end > $time->startsAt) {
                return false;
            }
        }
        return true;
    }

    public function requireTime(AppointmentTime $time): void
    {
        if (!$this->permits($time)) {
            throw new ValidationException([['field' => 'start', 'message' => trans(
                'This advising time overlaps a published class for a participant. Choose another time.'
            )]]);
        }
    }

    public function sameSources(self $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['advising_class_calendar' => '[private current schedule]'];
    }
}
