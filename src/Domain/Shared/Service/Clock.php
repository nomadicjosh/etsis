<?php

declare(strict_types=1);

namespace Domain\Shared\Service;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
