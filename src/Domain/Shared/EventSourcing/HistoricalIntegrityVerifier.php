<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

/** Deployment-operator boundary, never a browser/reporting API. Does not run projectors or event handlers. */
interface HistoricalIntegrityVerifier
{
    public function verify(
        #[\SensitiveParameter] ?string $cursor = null,
        int $streamLimit = 10,
        int $eventLimit = 500,
    ): HistoryVerificationResult;
}
