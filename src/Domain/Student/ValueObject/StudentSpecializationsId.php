<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentSpecializations;
use Qubus\ValueObjects\Identity\Ulid;

final class StudentSpecializationsId extends Ulid implements AggregateId
{
    public function __construct(string $value)
    {
        parent::__construct(new EntityId($value)->value);
    }
    public static function fromString(string $value): self
    {
        return new self($value);
    }
    public function aggregateClassName(): string
    {
        return StudentSpecializations::class;
    }
}
