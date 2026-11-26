<?php

declare(strict_types=1);

namespace Domain\Person;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Person\Event\PersonSsnWasRecorded;
use Domain\Person\ValueObject\SocialSecurityNumber;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** A private field of the canonical person, in the same authoritative protected journal. */
final class PersonSsnHistory extends EventSourcedAggregate implements \JsonSerializable
{
    public const array FIELDS = ['person_id', 'owner_user_id', 'identity_version', 'number', 'intent', 'privacy_nonce'];
    private ?string $lastFour = null;
    private array $receipts = [];

    public static function validate(#[\SensitiveParameter] array $data): void
    {
        try {
            if (
                count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []
                || !is_int($data['identity_version']) || $data['identity_version'] < 0
                || $data['identity_version'] > 2147483647
            ) {
                throw new \InvalidArgumentException();
            }
            foreach (['person_id', 'owner_user_id'] as $key) {
                if (!is_string($data[$key]) || new EntityId($data[$key])->value !== $data[$key]) {
                    throw new \InvalidArgumentException();
                }
            }
            if (
                $data['number'] !== null && (!is_string($data['number'])
                || new SocialSecurityNumber($data['number'])->digits() !== $data['number'])
            ) {
                throw new \InvalidArgumentException();
            }
            foreach (['intent', 'privacy_nonce'] as $key) {
                if (!is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function intent(
        string $person,
        string $owner,
        int $identityVersion,
        EntityId $actor,
        int $version,
        #[\SensitiveParameter] ?SocialSecurityNumber $number,
        #[\SensitiveParameter] string $reason,
    ): string {
        return hash('sha256', json_encode([$person, $owner, $identityVersion, $actor->value,
            $version, $number?->digits(), $reason], JSON_THROW_ON_ERROR));
    }

    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        $context = $stored->context;
        if (
            !$event instanceof PersonSsnWasRecorded || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1 || $context->origin !== 'command'
            || $context->actor === null || $context->reason === null || trim($context->reason) === ''
            || $context->effectiveAt === null || $context->correlation === null
            || isset($this->receipts[$context->correlation->value])
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($data = $event->payload());
        try {
            if (self::reason($context->reason) !== $context->reason) {
                throw new CorruptHistoryException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
        if (
            $data['person_id'] !== (string) $this->aggregateId() || !hash_equals($data['intent'], self::intent(
                $data['person_id'],
                $data['owner_user_id'],
                $data['identity_version'],
                $context->actor,
                $this->playhead(),
                $data['number'] === null ? null : new SocialSecurityNumber($data['number']),
                $context->reason
            ))
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->receipts[$context->correlation->value] = $stored;
    }

    protected function whenPersonSsnWasRecorded(PersonSsnWasRecorded $event): void
    {
        $this->lastFour = $event->payload()['number'] === null ? null
        : new SocialSecurityNumber($event->payload()['number'])->lastFour();
    }

    public function lastFour(): ?string
    {
        return $this->lastFour;
    }

    public static function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            || preg_match('/[0-9]{3}[\s-]?[0-9]{2}[\s-]?[0-9]{4}/', $reason)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'reason', 'message' => trans_html(
                'Enter a correction reason (up to 1000 characters), without the SSN or other nine-digit numbers.'
            )]]);
        }
        return $reason;
    }

    public function receipt(EntityId $operation): ?StoredEvent
    {
        return $this->receipts[$operation->value] ?? null;
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['person_ssn_history' => '[protected]'];
    }
}
