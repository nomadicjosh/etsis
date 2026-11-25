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

/** Immutable operational configuration revision, with one original save receipt. */
final readonly class BookingPolicyRevision implements JsonSerializable
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function reason(#[\SensitiveParameter] string $reason): string
    {
        if (
            !mb_check_encoding($reason, 'UTF-8') || trim($reason) === '' || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([['field' => 'reason',
                'message' => trans('Enter a policy-change reason of 1–1,000 characters.')]]);
        }
        return trim($reason);
    }

    public static function intent(
        EntityId $id,
        int $expected,
        array $rules,
        #[\SensitiveParameter] string $reason
    ): string {
        $rules = new BookingPolicySnapshot($id, $expected + 1, $rules)->rules;
        return hash('sha256', json_encode(
            [$id->value, $expected, $rules, self::reason($reason)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public static function create(
        EntityId $id,
        int $expected,
        array $rules,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        string $at
    ): self {
        $snapshot = new BookingPolicySnapshot($id, $expected + 1, $rules);
        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $at, new DateTimeZone('UTC'));
        if ($expected < 0 || $stamp === false || $stamp->format('Y-m-d\TH:i:s\Z') !== $at) {
            throw new \InvalidArgumentException('Use an explicit policy revision and recording instant.');
        }
        return new self(['purpose' => 'advising.booking_policy', 'id' => $id->value, 'revision' => $snapshot->revision,
            'rules' => $snapshot->rules, 'reason' => self::reason($reason), 'at' => $at,
            'receipt' => ['actor_id' => $actor->value, 'intent' => self::intent($id, $expected, $rules, $reason),
                'result' => ['id' => $id->value, 'revision' => $snapshot->revision]]]);
    }

    public function retry(EntityId $actor, string $intent): array
    {
        if (
            $this->data['receipt']['actor_id'] !== $actor->value || !hash_equals(
                $this->data['receipt']['intent'],
                $intent
            )
        ) {
            throw new ConcurrentModificationException(trans(
                'This booking policy change was accepted with different details.'
            ));
        }
        return $this->data['receipt']['result'];
    }

    public function snapshot(): BookingPolicySnapshot
    {
        return new BookingPolicySnapshot(
            new EntityId($this->data['id']),
            $this->data['revision'],
            $this->data['rules']
        );
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function view(): array
    {
        return array_intersect_key($this->data, array_flip(['id', 'revision', 'rules', 'reason', 'at']));
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            if (($data['purpose'] ?? null) !== 'advising.booking_policy') {
                throw new CorruptHistoryException();
            }
            $item = self::create(
                new EntityId($data['id']),
                $data['revision'] - 1,
                $data['rules'],
                new EntityId($data['receipt']['actor_id']),
                $data['reason'],
                $data['at']
            );
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
        return ['advising_booking_policy' => '[private operational configuration]'];
    }
}
