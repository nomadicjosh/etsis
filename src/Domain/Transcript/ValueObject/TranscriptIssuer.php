<?php

declare(strict_types=1);

namespace Domain\Transcript\ValueObject;

use InvalidArgumentException;

final readonly class TranscriptIssuer
{
    public function __construct(public string $name = "", public string $address = "")
    {
        if (
            !mb_check_encoding($name . $address, "UTF-8") ||
            mb_strlen($name) > 191 ||
            mb_strlen($address) > 2000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name . $address)
        ) {
            throw new InvalidArgumentException("Configure valid transcript issuer details.");
        }
    }
}
