<?php

declare(strict_types=1);

namespace Domain\Shared\Security;

use Domain\Shared\ValueObject\EntityId;

interface StudentAccess
{
    public function authenticatedUserId(): EntityId;

    public function requirePermission(string $permission): EntityId;

    public function requireOwnerOrPermission(?EntityId $ownerUserId, string $permission): EntityId;
}
