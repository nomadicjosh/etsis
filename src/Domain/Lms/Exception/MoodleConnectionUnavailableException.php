<?php

declare(strict_types=1);

namespace Domain\Lms\Exception;

final class MoodleConnectionUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            "Moodle check failed. Verify the site URL, TLS trust, REST service and token settings.",
        );
    }
}
