<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\EmergencyContactsRepository;
use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlEmergencyContactsRepository implements EmergencyContactsRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function current(EntityId $person): ?EmergencyContacts
    {
        $row = $this->sql->rows('sis_person_emergency_contacts', ['person_id' => $person->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            if (
                !is_string($row['encrypted_contacts']) || strlen($row['encrypted_contacts']) > 131072
                || (string) (int) $row['version'] !== (string) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            $contacts = json_decode($this->cipher->open($row['encrypted_contacts']), true, 6, JSON_THROW_ON_ERROR);
            if (!is_array($contacts)) {
                throw new CorruptHistoryException();
            }
            return EmergencyContacts::restore(['contacts' => $contacts, 'version' => (int) $row['version']]);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(EntityId $person, EmergencyContacts $contacts, int $expectedVersion): void
    {
        if ($contacts->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException('Reload the emergency contacts.');
        }
        $ciphertext = $this->cipher->seal(json_encode($contacts->contacts, JSON_THROW_ON_ERROR));
        if ($expectedVersion === 0 && $this->current($person) === null) {
            $this->sql->insert('sis_person_emergency_contacts', ['id' => EntityId::generate()->value,
                'person_id' => $person->value, 'encrypted_contacts' => $ciphertext, 'version' => $contacts->version]);
        } elseif (
            $this->sql->update('sis_person_emergency_contacts', ['encrypted_contacts' => $ciphertext,
            'version' => $contacts->version], ['person_id' => $person->value, 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the emergency contacts.');
        }
    }
}
