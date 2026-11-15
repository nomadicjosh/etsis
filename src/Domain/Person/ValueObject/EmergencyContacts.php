<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** An ordered person-owned value, not a directory of campus members. */
final readonly class EmergencyContacts
{
    public const int LIMIT = 5;
    /** @param list<array{name:string,relationship:string,phone:string,email:string}> $contacts */
    private function __construct(public array $contacts, public int $version)
    {
    }
    /** @param array<mixed> $input */
    public static function fromInput(#[\SensitiveParameter] array $input, int $version): self
    {
        if (!array_is_list($input) || count($input) > self::LIMIT || $version < 1) {
            throw new ValidationException([['field' => 'contacts', 'message' => 'Use up to five emergency contacts.']]);
        }
        $contacts = [];
        $seen = [];
        foreach ($input as $index => $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['name', 'relationship', 'phone', 'email']) !== []) {
                throw new ValidationException([['field' => 'contacts', 'message' => 'Review the contact fields.']]);
            }
            $contact = [];
            foreach (['name' => 191, 'relationship' => 80, 'phone' => 60, 'email' => 191] as $field => $limit) {
                $raw = $row[$field] ?? (in_array($field, ['relationship', 'email'], true) ? '' : null);
                if (
                    !is_string($raw) || !mb_check_encoding($raw, 'UTF-8') || mb_strlen($raw) > $limit
                    || preg_match('/[\x00-\x1f\x7f]/', $raw)
                    || (in_array($field, ['name', 'phone'], true) && trim($raw) === '')
                    || ($field === 'email' && trim($raw) !== ''
                    && filter_var(trim($raw), FILTER_VALIDATE_EMAIL) === false)
                ) {
                    throw new ValidationException([['field' => 'contacts.' . $index . '.' . $field,
                        'message' => 'Enter a valid ' . $field . ' for this contact.']]);
                }
                $contact[$field] = trim($raw);
            }
            $contact = ['name' => $contact['name'], 'relationship' => $contact['relationship'],
                'phone' => $contact['phone'], 'email' => $contact['email']];
            $fingerprint = json_encode($contact, JSON_THROW_ON_ERROR);
            if (isset($seen[$fingerprint])) {
                throw new ValidationException([['field' => 'contacts', 'message' => 'Remove the repeated contact.']]);
            }
            $seen[$fingerprint] = true;
            $contacts[] = $contact;
        }
        return new self($contacts, $version);
    }
    /** @return array{contacts:list<array{name:string,relationship:string,phone:string,email:string}>,version:int} */
    public function facts(): array
    {
        return ['contacts' => $this->contacts, 'version' => $this->version];
    }
    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): self
    {
        if (count($facts) !== 2 || !is_array($facts['contacts'] ?? null) || !is_int($facts['version'] ?? null)) {
            throw new CorruptHistoryException();
        }
        try {
            $value = self::fromInput($facts['contacts'], $facts['version']);
            // Compare canonical field order strictly, including numeric-looking phone strings.
            foreach ($facts['contacts'] as $index => $contact) {
                if (!is_array($contact) || count($contact) !== 4) {
                    throw new CorruptHistoryException();
                }
                foreach ($value->contacts[$index] as $field => $content) {
                    if (($contact[$field] ?? null) !== $content) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            return $value;
        } catch (ValidationException) {
            throw new CorruptHistoryException();
        }
    }
    /** @param array<string,mixed> $payload */
    public static function validateEvent(#[\SensitiveParameter] array $payload): void
    {
        $fields = ['operation_id', 'previous_operation_id', 'facts', 'intent'];
        if (
            count($payload) !== 4 || array_diff(array_keys($payload), $fields) !== []
            || !is_string($payload['operation_id']) || !is_string($payload['previous_operation_id'])
            || !is_array($payload['facts']) || !is_string($payload['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload['operation_id']);
        new EntityId($payload['previous_operation_id']);
        self::restore($payload['facts']);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['contacts' => '[protected emergency contact details]'];
    }
}
