<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

final class TransactionScope
{
    public bool $rollbackOnly = false;
}
