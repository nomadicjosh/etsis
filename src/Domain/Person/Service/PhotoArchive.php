<?php

declare(strict_types=1);

namespace Domain\Person\Service;

interface PhotoArchive
{
    public function entries(
        #[\SensitiveParameter] string $bytes,
        string $filename,
    ): array;
    public function read(
        #[\SensitiveParameter] string $bytes,
        string $filename,
        array $entry,
    ): string;
}
