<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\EnrollmentLoadPolicy;
use Qubus\ValueObjects\Identity\Ulid;

final class EnrollmentLoadPolicyId extends Ulid implements AggregateId
{
    public const string GLOBAL = "01K00000000000000000000E10";

    public function __construct(string $value)
    {
        parent::__construct(new EntityId($value)->value);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function scope(?EntityId $program): self
    {
        if ($program?->value === self::GLOBAL) {
            throw new \InvalidArgumentException(
                "This reference is reserved for the institutional load policy.",
            );
        }
        return new self($program?->value ?? self::GLOBAL);
    }

    public function aggregateClassName(): string
    {
        return EnrollmentLoadPolicy::class;
    }
}
