<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Domain\Shared\ValueObject\EntityId;

interface OwnerEmailVerification
{
    /** Authentication evidence only; staff intake and earlier accepted receipts retain their own contracts. */
    public function requireForApplication(EntityId $owner, EntityId $person): void;
}
