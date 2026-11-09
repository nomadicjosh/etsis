<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

final class CorruptHistoryException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct("Historical event integrity could not be verified.");
    }
}
