<?php

declare(strict_types=1);

namespace Domain\Reporting;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class StudentReport
{
    public function __construct(
        public EntityId $id,
        public EntityId $ownerUserId,
        public string $name,
        public ?EntityId $programId = null,
        public ?EntityId $termId = null,
        public ?bool $active = null,
    ) {
        if (trim($name) === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A report name is required.');
        }
    }
}
