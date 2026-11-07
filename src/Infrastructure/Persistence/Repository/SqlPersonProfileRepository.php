<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Person\Repository\PersonProfileRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPersonProfileRepository implements PersonProfileRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(EntityId $person): void
    {
        $this->sql->lock('sis_persons', $person->value, 'id');
    }

    public function save(EntityId $person, string $givenName, string $familyName, bool $directoryRestricted): void
    {
        $this->sql->update('sis_persons', ['given_name' => $givenName, 'family_name' => $familyName,
            'directory_restricted' => (int) $directoryRestricted], ['id' => $person->value]);
    }
}
