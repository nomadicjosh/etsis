<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\PersonRestrictions;
use Qubus\ValueObjects\Identity\Ulid;

final class PersonRestrictionsId extends Ulid implements AggregateId
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
        return PersonRestrictions::class;
    }
}
