<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

/** Fully authenticated private output, prepared before any response bytes are released. */
final readonly class ReportExportDownload implements \JsonSerializable
{
    /** @param list<string> $chunks */
    public function __construct(#[\SensitiveParameter] public array $chunks, public int $bytes)
    {
    }

    public function __debugInfo(): array
    {
        return ['download' => '[private report output]'];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
