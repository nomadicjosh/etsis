<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Payments\Service\GatewayPolicy;

final class FictionalGatewayPolicy implements GatewayPolicy
{
    public bool $active = true;
    public bool $test = true;
    public string $base = 'https://campus.example.invalid';
    public function enabled(): bool
    {
        return $this->active;
    }
    public function allowTest(): bool
    {
        return $this->test;
    }
    public function baseUrl(): ?string
    {
        return $this->base;
    }
}
