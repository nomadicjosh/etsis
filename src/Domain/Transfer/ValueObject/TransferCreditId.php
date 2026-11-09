<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\TransferCredit;
use Qubus\ValueObjects\Identity\Ulid;

final class TransferCreditId extends Ulid implements AggregateId
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
        return TransferCredit::class;
    }
}
