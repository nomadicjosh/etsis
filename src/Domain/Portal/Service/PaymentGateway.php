<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

interface PaymentGateway
{
    public function enabled(): bool;

    /** @return array{session: string, url: string} */
    public function checkout(EntityId $payment, Money $amount, int $expiresAt): array;

    /** @return array{payment: string, session: string, minor: int, currency: string}|null */
    public function verify(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): ?array;
}
