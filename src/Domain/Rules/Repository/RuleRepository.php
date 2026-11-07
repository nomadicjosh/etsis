<?php

declare(strict_types=1);

namespace Domain\Rules\Repository;

use Domain\Rules\RuleSet;
use Domain\Shared\ValueObject\EntityId;

interface RuleRepository
{
    public function save(EntityId $id, EntityId $owner, string $name, RuleSet $rules): void;
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function students(RuleSet $rules): array;
}
