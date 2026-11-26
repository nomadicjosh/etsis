<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Person\Repository\PersonSsnRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPersonSsnRepository implements PersonSsnRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function find(EntityId $person): ?array
    {
        return $this->sql->rows('sis_person_ssns', ['id' => $person->value], 1)[0] ?? null;
    }

    public function save(EntityId $person, int $expectedVersion, #[\SensitiveParameter] string $sealed): void
    {
        $data = ['version' => $expectedVersion + 1, 'sealed_data' => $sealed];
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_person_ssns', ['id' => $person->value] + $data);
        } else {
            $this->sql->update('sis_person_ssns', $data, ['id' => $person->value, 'version' => $expectedVersion]);
        }
    }
}
