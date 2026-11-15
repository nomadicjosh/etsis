<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Geography\CountryCode;
use Locale;
use Throwable;

/** Dated assertions about a person's addresses, independently of their academic pursuits. */
final readonly class AddressBook
{
    public const int LIMIT = 10;
    public const int MAX_BYTES = 55000;
    public const array PURPOSES = ['mailing' => 'Mailing', 'home' => 'Home', 'billing' => 'Billing',
        'campus' => 'Campus', 'other' => 'Other'];
    /** @param list<array{id:string,purpose:string,lines:string,locality:string,region:string,postal_code:string,country_code:string,valid_from:string,valid_until:?string}> $addresses */
    private function __construct(public array $addresses, public int $version, public bool $mailingManaged)
    {
    }
    /** @param array<mixed> $input */
    public static function fromInput(#[\SensitiveParameter] array $input, int $version, bool $mailingManaged): self
    {
        if (!array_is_list($input) || count($input) > self::LIMIT || $version < 1) {
            self::invalid('addresses', 'Use up to ten dated addresses.');
        }
        $addresses = [];
        $ids = [];
        foreach ($input as $index => $row) {
            $path = 'addresses.' . $index;
            $keys = ['id', 'purpose', 'lines', 'locality', 'region', 'postal_code', 'country_code',
                'valid_from', 'valid_until'];
            if (!is_array($row) || array_diff(array_keys($row), $keys) !== []) {
                self::invalid($path, 'Review the address fields.');
            }
            try {
                $id = new EntityId(is_string($row['id'] ?? null) ? $row['id'] : '');
            } catch (Throwable) {
                self::invalid($path, 'Add the address again to obtain its identity.');
            }
            if (isset($ids[$id->value])) {
                self::invalid($path, 'Each address needs its own identity.');
            }
            $ids[$id->value] = true;
            if (!is_string($row['purpose'] ?? null) || !isset(self::PURPOSES[$row['purpose']])) {
                self::invalid($path . '.purpose', 'Choose the address purpose.');
            }
            $address = ['id' => $id->value, 'purpose' => $row['purpose'], 'lines' => '', 'locality' => '',
                'region' => '', 'postal_code' => '', 'country_code' => ''];
            foreach (
                ['lines' => 500, 'locality' => 120, 'region' => 120, 'postal_code' => 32,
                'country_code' => 2] as $field => $limit
            ) {
                $raw = $row[$field] ?? '';
                if (
                    !is_string($raw) || !mb_check_encoding($raw, 'UTF-8') || mb_strlen($raw) > $limit
                    || preg_match($field === 'lines' ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/'
                        : '/[\x00-\x1f\x7f]/', $raw)
                ) {
                    self::invalid($path . '.' . $field, 'Enter a shorter valid value.');
                }
                $address[$field] = trim($raw);
            }
            if ($address['lines'] === '') {
                self::invalid($path . '.lines', 'Enter the street or postal address.');
            }
            try {
                $address['country_code'] = (string) CountryCode::fromNative(strtoupper($address['country_code']));
            } catch (Throwable) {
                self::invalid($path . '.country_code', 'Choose a country.');
            }
            $address['valid_from'] = self::date($row['valid_from'] ?? null, $path . '.valid_from');
            $until = $row['valid_until'] ?? null;
            $address['valid_until'] = $until === null || $until === '' ? null
            : self::date($until, $path . '.valid_until');
            if ($address['valid_until'] !== null && $address['valid_until'] < $address['valid_from']) {
                self::invalid($path . '.valid_until', 'The through date cannot precede the from date.');
            }
            foreach ($addresses as $earlier) {
                if (
                    $earlier['purpose'] === $address['purpose']
                    && $earlier['valid_from'] <= ($address['valid_until'] ?? '9999-12-31')
                    && $address['valid_from'] <= ($earlier['valid_until'] ?? '9999-12-31')
                ) {
                    self::invalid($path . '.valid_from', self::PURPOSES[$address['purpose']]
                        . ' address dates overlap. End the earlier address before the next starts.');
                }
            }
            $mailingManaged = $mailingManaged || $address['purpose'] === 'mailing';
            $addresses[] = ['id' => $address['id'], 'purpose' => $address['purpose'],
                'lines' => $address['lines'], 'locality' => $address['locality'], 'region' => $address['region'],
                'postal_code' => $address['postal_code'], 'country_code' => $address['country_code'],
                'valid_from' => $address['valid_from'], 'valid_until' => $address['valid_until']];
        }
        if (strlen(json_encode($addresses, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            self::invalid('addresses', 'The address list is too large. Shorten some entries.');
        }
        return new self($addresses, $version, $mailingManaged);
    }
    private static function date(mixed $raw, string $field): string
    {
        if (
            !is_string($raw) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $raw) !== 1
            || $raw < '0001-01-01' || $raw > '9999-12-31'
        ) {
            self::invalid($field, 'Enter a valid calendar date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            self::invalid($field, 'Enter a valid calendar date.');
        }
        return $raw;
    }
    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([['field' => $field, 'message' => $message]]);
    }
    /** @return array{addresses:list<array{id:string,purpose:string,lines:string,locality:string,region:string,postal_code:string,country_code:string,valid_from:string,valid_until:?string}>,version:int,mailing_managed:bool} */
    public function facts(): array
    {
        return ['addresses' => $this->addresses, 'version' => $this->version,
            'mailing_managed' => $this->mailingManaged];
    }
    public static function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion('-' . $code, 'en');
        return $name === false || $name === '' ? $code : $name;
    }
    public function mailingOn(string $date): string
    {
        self::date($date, 'date');
        foreach ($this->addresses as $address) {
            if (
                $address['purpose'] !== 'mailing' || $address['valid_from'] > $date
                || ($address['valid_until'] !== null && $address['valid_until'] < $date)
            ) {
                continue;
            }
            $place = implode(', ', array_filter(
                [$address['locality'], $address['region']],
                static fn(string $part): bool => $part !== ''
            ));
            $place = trim($place . ' ' . $address['postal_code']);
            return implode("\n", array_filter(
                [$address['lines'], $place,
                self::countryName($address['country_code'])],
                static fn(string $part): bool => $part !== ''
            ));
        }
        return '';
    }
    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): self
    {
        try {
            if (
                count($facts) !== 3 || !is_array($facts['addresses'] ?? null)
                || !is_int($facts['version'] ?? null) || !is_bool($facts['mailing_managed'] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $book = self::fromInput($facts['addresses'], $facts['version'], $facts['mailing_managed']);
            if ($book->mailingManaged !== $facts['mailing_managed']) {
                throw new CorruptHistoryException();
            }
            foreach ($facts['addresses'] as $index => $row) {
                if (count($row) !== 9) {
                    throw new CorruptHistoryException();
                }
                foreach ($book->addresses[$index] as $field => $value) {
                    if (!array_key_exists($field, $row) || $row[$field] !== $value) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            return $book;
        } catch (Throwable) {
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
        return ['addresses' => '[protected dated addresses]'];
    }
}
