<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Finance\StudentAccount;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class AccountId extends Ulid implements AggregateId
{
    public function __construct(?string $value = null)
    {
        $id = $value === null ? EntityId::generate() : new EntityId($value);
        parent::__construct($id->value);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function aggregateClassName(): string
    {
        return StudentAccount::class;
    }
}
