<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

final readonly class AdmissionDocumentPolicy
{
    public const int MAX_BYTES = 5242880;
    public function __construct(public bool $enabled, public int $retentionDays, public bool $scannerReviewed = false)
    {
    }
    public function acceptsUploads(): bool
    {
        return $this->enabled && $this->scannerReviewed && $this->retentionDays >= 1 && $this->retentionDays <= 3650;
    }
}
