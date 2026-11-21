<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\HumanResources\TimeApprovalPolicy;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class TimeApprovalPolicyId extends Ulid implements AggregateId
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
        return TimeApprovalPolicy::class;
    }
}
