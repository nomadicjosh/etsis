<?php

declare(strict_types=1);

namespace Domain\Student\Query;

use Codefy\QueryBus\Query;
use Domain\Shared\ValueObject\EntityId;

final readonly class FindStudentQuery implements Query
{
    public function __construct(public EntityId $studentId)
    {
    }
}
