<?php

declare(strict_types=1);

namespace Domain\Advising;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\LocalDateTime;
use Exception;

use function Codefy\Framework\Helpers\trans;

/** Explicit institution-local minutes, with unique UTC instants across clock changes. */
final readonly class AppointmentTime
{
    public string $startsAt;
    public string $endsAt;

    public function __construct(public string $start, public string $end, public string $timezone)
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            throw new ValidationException([['field' => 'timezone', 'message' => trans(
                'Use the institution timezone supplied by the appointment form.'
            )]]);
        }
        $from = LocalDateTime::resolve($start, $zone, 'start');
        $through = LocalDateTime::resolve($end, $zone, 'end');
        $minutes = ($through->getTimestamp() - $from->getTimestamp()) / 60;
        if ($minutes < 5 || $minutes > 240 || substr($start, 0, 10) !== substr($end, 0, 10)) {
            throw new ValidationException([['field' => 'end', 'message' => trans(
                'Choose a same-day advising appointment lasting 5–240 minutes.'
            )]]);
        }
        $utc = new DateTimeZone('UTC');
        $this->startsAt = $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $this->endsAt = $through->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
    }

    public function requireFuture(DateTimeImmutable $now): void
    {
        $start = new DateTimeImmutable($this->startsAt);
        if ($start <= $now || $start > $now->modify('+366 days')) {
            throw new ValidationException([['field' => 'start', 'message' => trans(
                'Choose a future appointment within the next 366 days.'
            )]]);
        }
    }

    public function facts(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'timezone' => $this->timezone,
            'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt];
    }
}
