<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Finance\BillingRule;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class BillingRuleId extends Ulid implements AggregateId
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
        return BillingRule::class;
    }
}
