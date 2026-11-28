<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

/** Only decoded, scanned and re-encoded image data crosses this port. */
final readonly class ProfilePhoto
{
    public function __construct(#[\SensitiveParameter] public string $bytes)
    {
        if (strlen($bytes) < 1 || strlen($bytes) > 1048576) {
            throw new \InvalidArgumentException("Invalid normalized photo.");
        }
    }

    public function digest(): string
    {
        return hash("sha256", $this->bytes);
    }
}
