<?php

declare(strict_types=1);

namespace Domain\Assistance\Exception;

use Domain\Assistance\ValueObject\AssistanceFailure;

final class AssistanceUnavailableException extends \DomainException
{
    public function __construct(public readonly AssistanceFailure $failure = AssistanceFailure::Unavailable)
    {
        parent::__construct($failure->message());
    }
}
