<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Registration\DropPolicyPublication;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

/** The protected stream type retains the scope when the journal reconstructs this identity. */
final class DropPolicyPublicationId extends Ulid implements AggregateId
{
    public function __construct(string $value, public readonly bool $isSection = false)
    {
        parent::__construct(new EntityId($value)->value);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function aggregateClassName(): string
    {
        return DropPolicyPublication::class;
    }
}
