<?php

declare(strict_types=1);

namespace Domain\Lms\Exception;

use Domain\Lms\ValueObject\MoodleSetupIssue;

final class MoodleConnectionUnavailableException extends \DomainException
{
    public function __construct(public readonly ?MoodleSetupIssue $setupIssue = null)
    {
        parent::__construct(
            $setupIssue?->message() ??
                "Moodle check failed. Verify the site URL, TLS trust, REST service and token settings.",
        );
    }
}
