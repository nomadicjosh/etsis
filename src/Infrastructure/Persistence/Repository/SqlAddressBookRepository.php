<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\AddressBookRepository;
use Domain\Person\ValueObject\AddressBook;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlAddressBookRepository implements AddressBookRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function current(EntityId $person): ?AddressBook
    {
        $row = $this->sql->rows('sis_person_addresses', ['person_id' => $person->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            if (
                !is_string($row['encrypted_addresses']) || strlen($row['encrypted_addresses']) > 131072
                || (string) (int) $row['version'] !== (string) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            $data = json_decode($this->cipher->open($row['encrypted_addresses']), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) || count($data) !== 3 || ($data['purpose'] ?? null) !== 'person.addresses.v1'
                || ($data['person_id'] ?? null) !== $person->value || !is_array($data['facts'] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $book = AddressBook::restore($data['facts']);
            if ($book->version !== (int) $row['version']) {
                throw new CorruptHistoryException();
            }
            return $book;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(EntityId $person, AddressBook $book, int $expectedVersion): void
    {
        if ($book->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException('Reload the addresses.');
        }
        $sealed = $this->cipher->seal(json_encode(
            ['purpose' => 'person.addresses.v1',
            'person_id' => $person->value, 'facts' => $book->facts()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if (strlen($sealed) > 131072) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'addresses',
                'message' => 'Use shorter address details.']]);
        }
        if ($expectedVersion === 0 && $this->current($person) === null) {
            $this->sql->insert('sis_person_addresses', ['id' => EntityId::generate()->value,
                'person_id' => $person->value, 'encrypted_addresses' => $sealed, 'version' => $book->version]);
        } elseif (
            $this->sql->update('sis_person_addresses', ['encrypted_addresses' => $sealed,
            'version' => $book->version], ['person_id' => $person->value, 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the addresses.');
        }
    }
}
