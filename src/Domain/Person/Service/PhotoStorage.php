<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Shared\ValueObject\EntityId;

interface PhotoStorage
{
    public function put(
        EntityId $id,
        #[\SensitiveParameter] string $bytes,
    ): void;
    public function read(EntityId $id): string;
    public function remove(EntityId $id): void;
}
