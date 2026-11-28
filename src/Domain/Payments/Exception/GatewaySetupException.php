<?php

declare(strict_types=1);

namespace Domain\Payments\Exception;

use Domain\Payments\Enum\GatewaySetupIssue;
use DomainException;

/** Only an enumerated safe explanation crosses the private HTTP boundary. */
final class GatewaySetupException extends DomainException
{
    public function __construct(public readonly GatewaySetupIssue $issue)
    {
        parent::__construct('Payment setup could not be accepted.');
    }
}
