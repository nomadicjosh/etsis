<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

interface GatewayPolicy
{
    public function enabled(): bool;
    public function allowTest(): bool;
    public function baseUrl(): ?string;
}
