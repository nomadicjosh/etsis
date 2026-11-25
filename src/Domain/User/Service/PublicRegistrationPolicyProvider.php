<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Domain\User\ValueObject\PublicRegistrationPolicy;

interface PublicRegistrationPolicyProvider
{
    public function current(): PublicRegistrationPolicy;
}
