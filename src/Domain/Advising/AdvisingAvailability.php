<?php

declare(strict_types=1);

namespace Domain\Advising;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use JsonSerializable;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Immutable dated office hours with one explicit closure and bounded original working receipts. */
final class AdvisingAvailability implements JsonSerializable
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function create(
        EntityId $id,
        EntityId $host,
        EntityId $hostUser,
        EntityId $actor,
        AppointmentTime $time,
        int $minutes,
        #[\SensitiveParameter] string $message,
        string $at,
    ): self {
        self::times($time, $minutes);
        $time->requireFuture(new DateTimeImmutable($at));
        $message = AdvisingAppointment::message($message);
        $item = new self(['purpose' => 'advising.availability', 'id' => $id->value,
            'host_person_id' => $host->value, 'host_user_id' => $hostUser->value,
            'time' => $time->facts(), 'slot_minutes' => $minutes, 'message' => $message,
            'version' => 0, 'status' => 'open', 'created_at' => $at, 'closed_at' => null,
            'close_reason' => null, 'receipts' => []]);
        $item->receipt('create', $actor, $item->creationIntent($time, $minutes, $message));
        return $item;
    }

    public function creationIntent(AppointmentTime $time, int $minutes, #[\SensitiveParameter] string $message): string
    {
        return hash('sha256', json_encode(
            [$this->data['id'], $this->data['host_person_id'],
            $this->data['host_user_id'], $time->facts(), $minutes, AdvisingAppointment::message($message)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function retry(string $action, EntityId $actor, string $intent): ?array
    {
        $receipt = $this->data['receipts'][$action] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt['actor_id'] !== $actor->value || !hash_equals($receipt['intent'], $intent)) {
            throw new ConcurrentModificationException(trans(
                'This availability action was accepted with different details.'
            ));
        }
        return $receipt['result'];
    }

    public function close(EntityId $actor, int $version, #[\SensitiveParameter] string $reason, string $at): array
    {
        $reason = AdvisingAppointment::message($reason);
        $intent = hash('sha256', json_encode([$this->data['id'], $version, $reason], JSON_THROW_ON_ERROR));
        if (($result = $this->retry('close', $actor, $intent)) !== null) {
            return $result;
        }
        if ($version !== 0 || $this->data['version'] !== 0 || $at < $this->data['created_at']) {
            throw new ConcurrentModificationException(trans('Availability changed. Refresh its current state.'));
        }
        $this->data['version'] = 1;
        $this->data['status'] = 'closed';
        $this->data['closed_at'] = $at;
        $this->data['close_reason'] = $reason;
        return $this->receipt('close', $actor, $intent);
    }

    private function receipt(string $action, EntityId $actor, string $intent): array
    {
        $result = ['id' => $this->data['id'], 'version' => $this->data['version'], 'status' => $this->data['status']];
        $this->data['receipts'][$action] = ['actor_id' => $actor->value, 'intent' => $intent, 'result' => $result];
        return $result;
    }

    /** @return list<AppointmentTime> */
    public static function times(AppointmentTime $time, int $minutes): array
    {
        $length = (strtotime($time->endsAt) - strtotime($time->startsAt)) / 60;
        $zone = new DateTimeZone($time->timezone);
        $start = new DateTimeImmutable($time->startsAt)->setTimezone($zone);
        $end = new DateTimeImmutable($time->endsAt)->setTimezone($zone);
        if (
            $minutes < 5 || $minutes > 120 || $length < $minutes || (int) $length % $minutes !== 0
            || $start->getOffset() !== $end->getOffset()
        ) {
            throw new ValidationException([['field' => 'slot_minutes', 'message' => trans(
                'Use slots of 5–120 minutes that divide the availability window exactly, outside clock changes.'
            )]]);
        }
        $step = '+' . $minutes . ' minutes';
        $slots = [];
        for ($at = new DateTimeImmutable($time->startsAt); $at < $end; $at = $at->modify($step)) {
            $slots[] = new AppointmentTime(
                $at->setTimezone($zone)->format('Y-m-d\TH:i'),
                $at->modify($step)->setTimezone($zone)->format('Y-m-d\TH:i'),
                $zone->getName()
            );
        }
        return $slots;
    }

    /** @return list<AppointmentTime> */
    public function slots(): array
    {
        $t = $this->data['time'];
        return self::times(new AppointmentTime($t['start'], $t['end'], $t['timezone']), $this->data['slot_minutes']);
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function view(): array
    {
        return array_intersect_key($this->data, array_flip([
            'id', 'time', 'slot_minutes', 'message', 'version', 'status', 'created_at', 'closed_at', 'close_reason',
        ]));
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            if (
                ($data['purpose'] ?? null) !== 'advising.availability' || !is_array($data['receipts'])
                || count($data['receipts']) > 2
            ) {
                throw new CorruptHistoryException();
            }
            foreach (['created_at', 'closed_at'] as $field) {
                if ($field === 'closed_at' && $data[$field] === null) {
                    continue;
                }
                $stamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data[$field], new DateTimeZone('UTC'));
                if ($stamp === false || $stamp->format('Y-m-d\TH:i:s\Z') !== $data[$field]) {
                    throw new CorruptHistoryException();
                }
            }
            $t = $data['time'];
            $item = self::create(
                new EntityId($data['id']),
                new EntityId($data['host_person_id']),
                new EntityId($data['host_user_id']),
                new EntityId($data['receipts']['create']['actor_id']),
                new AppointmentTime($t['start'], $t['end'], $t['timezone']),
                $data['slot_minutes'],
                $data['message'],
                $data['created_at']
            );
            if ($data['closed_at'] !== null) {
                $item->close(
                    new EntityId($data['receipts']['close']['actor_id']),
                    0,
                    $data['close_reason'],
                    $data['closed_at']
                );
            }
            if ($item->facts() !== $data) {
                throw new CorruptHistoryException();
            }
            return $item;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['advising_availability' => '[private working record]'];
    }
}
