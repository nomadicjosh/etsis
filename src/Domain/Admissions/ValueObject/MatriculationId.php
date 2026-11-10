<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Admissions\Matriculation;
use Qubus\ValueObjects\Identity\Ulid;

final class MatriculationId extends Ulid implements AggregateId
{
    public function __construct(string $id)
    {
        parent::__construct(new EntityId($id)->value);
    }
    public static function fromString(string $id): self
    {
        return new self($id);
    }
    public function aggregateClassName(): string
    {
        return Matriculation::class;
    }
}
