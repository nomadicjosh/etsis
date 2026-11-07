<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use Domain\Shared\ValueObject\EntityId;

interface MailTransport
{
    /** Verify local configuration without contacting a provider. */
    public function assertReady(): void;

    public function send(
        EntityId $delivery,
        #[\SensitiveParameter] string $recipient,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $body
    ): void;
}
