<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\ValueObject\ContactDetails;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

/** Person-owned encrypted projection; keeps the existing table and recorded contact values. */
final readonly class SqlPersonContactRepository implements PersonContactRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function current(EntityId $person): ?ContactDetails
    {
        $row = $this->sql->rows('sis_portal_contacts', ['person_id' => $person->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            if (
                !is_string($row['encrypted_contact']) || strlen($row['encrypted_contact']) > 32768
                || (string) (int) $row['version'] !== (string) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            $fields = json_decode($this->cipher->open($row['encrypted_contact']), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($fields) || count($fields) !== 3 || array_key_exists('version', $fields)) {
                throw new CorruptHistoryException();
            }
            return ContactDetails::restore($fields + ['version' => (int) $row['version']]);
        } catch (Throwable) {
            // Keep encrypted values and parsing errors out of diagnostics.
            throw new CorruptHistoryException();
        }
    }

    public function save(EntityId $person, ContactDetails $contact, int $expectedVersion): void
    {
        if ($contact->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException('Reload your current contact information.');
        }
        $fields = $contact->facts();
        unset($fields['version']);
        $encrypted = $this->cipher->seal(json_encode($fields, JSON_THROW_ON_ERROR));
        if ($expectedVersion === 0 && $this->current($person) === null) {
            $this->sql->insert('sis_portal_contacts', ['id' => EntityId::generate()->value,
                'person_id' => $person->value, 'encrypted_contact' => $encrypted, 'version' => $contact->version]);
        } elseif (
            $this->sql->update('sis_portal_contacts', ['encrypted_contact' => $encrypted,
            'version' => $contact->version], ['person_id' => $person->value, 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Reload your current contact information.');
        }
    }
}
