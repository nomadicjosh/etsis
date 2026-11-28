<?php

declare(strict_types=1);

namespace Domain\Payments\Exception;

final class CapturePendingException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('An online payment is being finalized.');
    }
}
