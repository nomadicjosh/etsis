<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

interface NewsletterPolicy
{
    public function enabled(): bool;
    public function permitsHost(string $host): bool;
}
