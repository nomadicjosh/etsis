<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Domain\Shared\ValueObject\EntityId;

final readonly class Employee
{
    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public string $jobTitle,
        public bool $active,
    ) {
    }
}
