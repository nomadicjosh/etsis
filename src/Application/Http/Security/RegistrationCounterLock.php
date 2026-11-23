<?php

declare(strict_types=1);

namespace Application\Http\Security;

/** Serialize one counter update, without holding a lock during account creation. */
interface RegistrationCounterLock
{
    public function run(string $identifier, callable $increment): void;
}
