<?php

declare(strict_types=1);

namespace Domain\Access;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class AccessDefinition
{
    public function __construct(
        public EntityId $id,
        public string $name,
        public string $description = '',
        public bool $isSystem = false,
    ) {

        new \Domain\Access\ValueObject\AccessName($name);
        if (mb_strlen($description) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $description) !== 0) {
            throw new InvalidArgumentException('Invalid access description.');
        }
    }
}
