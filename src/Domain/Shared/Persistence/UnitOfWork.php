<?php

declare(strict_types=1);

namespace Domain\Shared\Persistence;

use Closure;

interface UnitOfWork
{
    public function run(Closure $operation): mixed;
}
