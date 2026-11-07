<?php

declare(strict_types=1);

namespace Domain\User\ValueObject;

use Domain\Access\ValueObject\AccessName;
use Qubus\ValueObjects\StringLiteral\StringLiteral;

/** Legacy event/profile metadata. Effective roles are database-managed access assignments. */
class UserRole extends StringLiteral
{
    public function __construct(string $value)
    {
        new AccessName($value);
        parent::__construct($value);
    }
}
