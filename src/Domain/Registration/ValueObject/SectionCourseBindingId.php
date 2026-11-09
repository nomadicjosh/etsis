<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Registration\SectionCourseBinding;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class SectionCourseBindingId extends Ulid implements AggregateId
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
        return SectionCourseBinding::class;
    }
}
