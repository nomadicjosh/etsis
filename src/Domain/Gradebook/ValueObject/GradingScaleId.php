<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Gradebook\GradingScale;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class GradingScaleId extends Ulid implements AggregateId
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
        return GradingScale::class;
    }
}
