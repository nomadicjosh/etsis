<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class ContactDetails
{
    public function __construct(
        public string $preferredName,
        public string $phone,
        public string $address,
        public int $version,
    ) {
        $errors = [];
        $fields = ['preferred_name' => [$preferredName, 80], 'phone' => [$phone, 40], 'address' => [$address, 500]];
        foreach ($fields as $field => [$value, $limit]) {
            if (
                !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || trim($value) !== $value
            ) {
                $errors[] = ['field' => $field, 'message' => 'Enter a shorter valid value.'];
            }
        }
        if ($version < 1) {
            $errors[] = ['field' => 'version', 'message' => 'Reload your current contact information.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int} */
    public function facts(): array
    {
        return ['preferred_name' => $this->preferredName, 'phone' => $this->phone,
            'address' => $this->address, 'version' => $this->version];
    }

    /** @param array<string,mixed> $facts */
    public static function restore(array $facts): self
    {
        $fields = ['preferred_name', 'phone', 'address', 'version'];
        if (
            count($facts) !== 4 || array_diff(array_keys($facts), $fields) !== []
            || !is_string($facts['preferred_name']) || !is_string($facts['phone']) || !is_string($facts['address'])
            || !is_int($facts['version'])
        ) {
            throw new CorruptHistoryException();
        }
        try {
            return new self($facts['preferred_name'], $facts['phone'], $facts['address'], $facts['version']);
        } catch (ValidationException) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string,mixed> $payload */
    public static function validateEvent(array $payload): void
    {
        $fields = ['operation_id', 'previous_operation_id', 'provenance', 'facts', 'intent'];
        if (
            count($payload) !== count($fields) || array_diff(array_keys($payload), $fields) !== []
            || !is_string($payload['operation_id']) || !is_string($payload['previous_operation_id'])
            || !in_array($payload['provenance'], ['created', 'observed', 'changed'], true)
            || !is_array($payload['facts']) || !is_string($payload['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload['operation_id']);
        new EntityId($payload['previous_operation_id']);
        $contact = self::restore($payload['facts']);
        if ($payload['provenance'] === 'created' && $contact->version !== 1) {
            throw new CorruptHistoryException();
        }
    }
}
