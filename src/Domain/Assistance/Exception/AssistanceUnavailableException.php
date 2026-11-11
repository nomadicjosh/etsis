<?php

declare(strict_types=1);

namespace Domain\Assistance\Exception;

final class AssistanceUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            "Optional assistance is unavailable. Your draft is unchanged; continue editing or try again later.",
        );
    }
}
