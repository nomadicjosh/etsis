<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Gradebook\GradeCertificationHistory;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class GradeCertificationId extends Ulid implements AggregateId
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
        return GradeCertificationHistory::class;
    }
}
