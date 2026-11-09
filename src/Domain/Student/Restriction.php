<?php

declare(strict_types=1);

namespace Domain\Student;

use DateTimeImmutable;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Restriction
{
    public const int REGISTRATION_BLOCKING_SEVERITY = 99;

    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public string $code,
        public int $severity,
        public DateRange $dates,
        public bool $blocksStaffRegistration = false,
    ) {
        if (
            !mb_check_encoding($code, 'UTF-8') || trim($code) === '' || mb_strlen($code) > 22
            || $severity < 0 || $severity > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $code)
        ) {
            throw new InvalidArgumentException('A valid restriction code and severity are required.');
        }
    }

    public function blocksRegistration(DateTimeImmutable $on, bool $byStaff): bool
    {
        return $this->state($on) === 'active'
        && ($byStaff ? $this->blocksStaffRegistration : $this->severity >= self::REGISTRATION_BLOCKING_SEVERITY);
    }

    public function state(DateTimeImmutable $on): string
    {
        $date = $on->format('Y-m-d');
        if ($date < $this->dates->start->format('Y-m-d')) {
            return 'scheduled';
        }

        return $this->dates->end !== null && $date > $this->dates->end->format('Y-m-d') ? 'ended' : 'active';
    }
}
