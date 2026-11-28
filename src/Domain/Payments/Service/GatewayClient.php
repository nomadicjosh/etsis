<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

interface GatewayClient
{
    /** Return only the authenticated merchant identifier; never retain an upstream response. */
    public function merchant(#[\SensitiveParameter] string $secret): string;
    /** @return array{session:string,url:string} */
    public function checkout(#[\SensitiveParameter] array $binding, string $baseUrl): array;
    public function verify(
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] string $signature,
        #[\SensitiveParameter] string $secret,
        bool $live,
    ): ?array;
}
