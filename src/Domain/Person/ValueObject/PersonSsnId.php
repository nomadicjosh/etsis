<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Person\PersonSsnHistory;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class PersonSsnId extends Ulid implements AggregateId
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
        return PersonSsnHistory::class;
    }
}
