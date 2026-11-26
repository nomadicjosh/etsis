<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;

interface NewsletterGateway
{
    /** Fixed outcome: accepted, pending, already_member, suppressed, retry, rejected, uncertain. */
    public function subscribe(
        #[\SensitiveParameter] NewsletterConnection $connection,
        #[\SensitiveParameter] array $contact,
        EntityId $operation
    ): string;
}
