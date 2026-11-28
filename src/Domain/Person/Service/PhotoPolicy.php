<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

final readonly class PhotoPolicy
{
    public const int MAX_IMAGE = 5242880;
    public const int MAX_ARCHIVE = 10485760;
    public const int MAX_FILES = 200;

    public function __construct(
        public bool $enabled,
        public bool $scannerReviewed,
        public int $retentionDays,
        public bool $gravatarEnabled,
    ) {
    }

    public function available(): bool
    {
        return $this->enabled &&
        $this->scannerReviewed &&
        $this->retentionDays >= 1 &&
        $this->retentionDays <= 365;
    }

    public function requireEnabled(): void
    {
        if (!$this->available()) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" =>
                        trans(
                            "Photo changes require enabled private storage, reviewed scanning "
                            . "and a retention policy."
                        ),
                ],
            ]);
        }
    }
}
