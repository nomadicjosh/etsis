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

/** Operational coordination receipt for a finite set of genuine dated offers. */
final class AdvisingAvailabilitySeries implements JsonSerializable
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function create(
        EntityId $id,
        EntityId $host,
        EntityId $account,
        EntityId $actor,
        string $channel,
        AvailabilityPattern $pattern,
        int $minutes,
        #[\SensitiveParameter] string $message,
        array $ids,
        string $at
    ): self {
        $times = $pattern->times($minutes);
        if (
            !in_array($channel, ['staff', 'advisor'], true) || !array_is_list($ids) || count($ids) !== count($times)
            || array_filter($ids, static fn(mixed $value): bool => !is_string($value)) !== []
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
        ) {
            throw new ValidationException([['field' => 'window_ids', 'message' => trans(
                'Review distinct dated windows before offering them.'
            )]]);
        }
        $windows = [];
        foreach ($times as $index => $time) {
            new EntityId($ids[$index]);
            $time->requireFuture(new DateTimeImmutable($at));
            $windows[] = ['id' => $ids[$index], 'time' => $time->facts()];
        }
        $item = new self(['purpose' => 'advising.availability_series', 'id' => $id->value,
            'host_person_id' => $host->value, 'host_user_id' => $account->value, 'channel' => $channel,
            'pattern' => $pattern->facts(), 'slot_minutes' => $minutes, 'message' =>
                AdvisingAppointment::message($message),
            'windows' => $windows, 'version' => 0, 'status' => 'open', 'created_at' => $at,
            'closed_at' => null, 'close_reason' => null, 'closed_ids' => [], 'receipts' => []]);
        $item->receipt('create', $actor, $item->creationIntent());
        return $item;
    }

    public function creationIntent(): string
    {
        return self::digest(array_intersect_key($this->data, array_flip(['id', 'host_person_id', 'host_user_id',
            'channel', 'pattern', 'slot_minutes', 'message', 'windows'])));
    }

    public static function digest(#[\SensitiveParameter] array $data): string
    {
        return hash('sha256', json_encode(
            $data,
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
                'This recurring availability action was accepted with different details.'
            ));
        }
        return $receipt['result'];
    }

    public function closureIntent(int $version, array $ids, #[\SensitiveParameter] string $reason): string
    {
        return self::digest([$this->data['id'], $version, $ids, AdvisingAppointment::message($reason)]);
    }

    public function close(
        EntityId $actor,
        int $version,
        array $ids,
        #[\SensitiveParameter] string $reason,
        string $at
    ): array {
        $reason = AdvisingAppointment::message($reason);
        $intent = $this->closureIntent($version, $ids, $reason);
        if (($result = $this->retry('close', $actor, $intent)) !== null) {
            return $result;
        }
        $members = array_column($this->data['windows'], 'id');
        if (
            $version !== 0 || $this->data['version'] !== 0 || $at < $this->data['created_at'] || !array_is_list($ids)
            || count($ids) > 20 || array_filter($ids, static fn(mixed $value): bool => !is_string($value)) !== []
            || $ids === [] || array_values(array_intersect($members, $ids)) !== $ids
        ) {
            throw new ConcurrentModificationException(trans(
                'Recurring availability changed. Review the remaining windows again.'
            ));
        }
        foreach ($this->data['windows'] as $window) {
            if (in_array($window['id'], $ids, true) && $window['time']['ends_at'] <= $at) {
                throw new ConcurrentModificationException(trans(
                    'A listed window expired. Review remaining-window closure again.'
                ));
            }
        }
        $this->data['version'] = 1;
        $this->data['status'] = 'closed';
        $this->data['closed_at'] = $at;
        $this->data['close_reason'] = $reason;
        $this->data['closed_ids'] = $ids;
        return $this->receipt('close', $actor, $intent);
    }

    private function receipt(string $action, EntityId $actor, string $intent): array
    {
        $result = ['id' => $this->data['id'], 'version' => $this->data['version'], 'status' => $this->data['status'],
            'window_ids' => $action === 'create' ? array_column(
                $this->data['windows'],
                'id'
            ) : $this->data['closed_ids']];
        $this->data['receipts'][$action] = ['actor_id' => $actor->value, 'intent' => $intent, 'result' => $result];
        return $result;
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function view(): array
    {
        return array_diff_key($this->data, array_flip(['purpose', 'host_person_id', 'host_user_id',
            'channel', 'receipts']));
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            if (
                ($data['purpose'] ?? null) !== 'advising.availability_series' || !is_array($data['receipts'])
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
            $item = self::create(
                new EntityId($data['id']),
                new EntityId($data['host_person_id']),
                new EntityId($data['host_user_id']),
                new EntityId($data['receipts']['create']['actor_id']),
                $data['channel'],
                new AvailabilityPattern($data['pattern']),
                $data['slot_minutes'],
                $data['message'],
                array_column($data['windows'], 'id'),
                $data['created_at']
            );
            if ($data['closed_at'] !== null) {
                $item->close(
                    new EntityId($data['receipts']['close']['actor_id']),
                    0,
                    $data['closed_ids'],
                    $data['close_reason'],
                    $data['closed_at']
                );
            }
            if ($data !== $item->facts()) {
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
        return ['advising_availability_series' => '[private working record]'];
    }
}
