<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\EducationBackgroundRepository;
use Domain\Person\ValueObject\EducationBackground;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlEducationBackgroundRepository implements EducationBackgroundRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function current(EntityId $person): ?EducationBackground
    {
        $row = $this->sql->rows('sis_person_education', ['person_id' => $person->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            if (
                !is_string($row['encrypted_background']) || strlen($row['encrypted_background']) > 1048576
                || (string) (int) $row['version'] !== (string) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            $background = json_decode($this->cipher->open($row['encrypted_background']), true, 6, JSON_THROW_ON_ERROR);
            if (!is_array($background)) {
                throw new CorruptHistoryException();
            }
            return EducationBackground::restore(['entries' => $background, 'version' => (int) $row['version']]);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(EntityId $person, EducationBackground $background, int $expectedVersion): void
    {
        if ($background->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException('Reload the educational background.');
        }
        $ciphertext = $this->cipher->seal(json_encode($background->entries, JSON_THROW_ON_ERROR));
        if ($expectedVersion === 0 && $this->current($person) === null) {
            $this->sql->insert('sis_person_education', ['id' => EntityId::generate()->value,
                'person_id' => $person->value, 'encrypted_background' => $ciphertext,
                'version' => $background->version]);
        } elseif (
            $this->sql->update('sis_person_education', ['encrypted_background' => $ciphertext,
            'version' => $background->version], ['person_id' => $person->value, 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the educational background.');
        }
    }
}
