<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

interface PaymentGateway
{
    public function enabled(string $purpose = 'account', ?string $currency = null): bool;

    /** Freeze the selected connection before creating a provider session. */
    public function prepare(EntityId $payment, array $record): array;

    /** Remove an API credential once its session is known; retain signing and financial evidence. */
    public function checkpoint(array $record): ?string;

    public function inspect(array $record): array;
    public function perform(array $record, array $plan): void;

    public function receipt(array $record): ?\Domain\Finance\ValueObject\GatewayReceipt;

    /** @return array{session: string, url: string} */
    public function checkout(EntityId $payment, Money $amount, int $expiresAt, string $purpose = 'account'): array;

    /** @return array{payment: string, session: string, minor: int, currency: string}|null */
    public function verify(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): ?array;
}
