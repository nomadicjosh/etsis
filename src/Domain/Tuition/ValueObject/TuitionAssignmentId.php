<?php

declare(strict_types=1);

namespace Domain\Tuition\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\TuitionAssignment;
use Qubus\ValueObjects\Identity\Ulid;

final class TuitionAssignmentId extends Ulid implements AggregateId
{
    public function __construct(?string $value = null)
    {
        parent::__construct(($value === null ? EntityId::generate() : new EntityId($value))->value);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function aggregateClassName(): string
    {
        return TuitionAssignment::class;
    }
}
