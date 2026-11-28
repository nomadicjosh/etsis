<?php

declare(strict_types=1);

namespace Domain\Payments\Enum;

enum GatewaySetupIssue: string
{
    case Credentials = 'credentials';
    case Merchant = 'merchant';
    case Asset = 'asset';
    case Identity = 'identity';
    case TestMode = 'test_mode';
}
