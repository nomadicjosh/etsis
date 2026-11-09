<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class HiatusReturnPlan
{
    public function __construct(
        public EntityId $operation,
        public DateTimeImmutable $on,
        public string $revision,
        public bool $reactivate,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new ValidationException([['field' => 'revision', 'message' => 'Review the current return plan.']]);
        }
    }
}
