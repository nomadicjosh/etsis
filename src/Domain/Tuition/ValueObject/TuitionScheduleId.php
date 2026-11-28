<?php

declare(strict_types=1);

namespace Domain\Tuition\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\TuitionSchedule;
use Qubus\ValueObjects\Identity\Ulid;

final class TuitionScheduleId extends Ulid implements AggregateId
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
        return TuitionSchedule::class;
    }
}
