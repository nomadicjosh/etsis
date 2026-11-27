<?php

declare(strict_types=1);

namespace Domain\User\Service;

interface EmailVerificationPolicy
{
    public function requiredForApplications(): bool;
    public function deliveryEnabled(): bool;
    public function origin(): string;
}
