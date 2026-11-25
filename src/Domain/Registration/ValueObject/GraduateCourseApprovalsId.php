<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Registration\GraduateCourseApprovals;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class GraduateCourseApprovalsId extends Ulid implements AggregateId
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
        return GraduateCourseApprovals::class;
    }
}
