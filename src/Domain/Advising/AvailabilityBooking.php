<?php

declare(strict_types=1);

namespace Domain\Advising;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use JsonSerializable;
use Throwable;

/** Compact retained source of the original self-booked time, independent of later office changes. */
final readonly class AvailabilityBooking implements JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private array $data)
    {
        try {
            $keys = ['window_id', 'window_version', 'host_person_id', 'host_user_id', 'window_time', 'slot_minutes'];
            if (
                count($data) !== 6 || array_diff($keys, array_keys($data)) !== []
                || $data['window_version'] !== 0 || !is_int($data['slot_minutes'])
            ) {
                throw new CorruptHistoryException();
            }
            foreach (['window_id', 'host_person_id', 'host_user_id'] as $key) {
                new EntityId($data[$key]);
            }
            $t = $data['window_time'];
            $time = new AppointmentTime($t['start'], $t['end'], $t['timezone']);
            if ($time->facts() !== $t) {
                throw new CorruptHistoryException();
            }
            AdvisingAvailability::times($time, $data['slot_minutes']);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function from(AdvisingAvailability $availability): self
    {
        $d = $availability->facts();
        return new self(['window_id' => $d['id'], 'window_version' => $d['version'],
            'host_person_id' => $d['host_person_id'], 'host_user_id' => $d['host_user_id'],
            'window_time' => $d['time'], 'slot_minutes' => $d['slot_minutes']]);
    }

    public function slot(string $start): ?AppointmentTime
    {
        $t = $this->data['window_time'];
        foreach (
            AdvisingAvailability::times(
                new AppointmentTime($t['start'], $t['end'], $t['timezone']),
                $this->data['slot_minutes']
            ) as $slot
        ) {
            if ($slot->start === $start) {
                return $slot;
            }
        }
        return null;
    }

    public function verify(EntityId $host, EntityId $account, AppointmentTime $time): void
    {
        if (
            $host->value !== $this->data['host_person_id'] || $account->value !== $this->data['host_user_id']
            || $this->slot($time->start)?->facts() !== $time->facts()
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['availability_booking' => '[private source]'];
    }
}
