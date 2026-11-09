<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

interface TranscriptDocumentRenderer
{
    /** @param array<string, mixed> $document */
    public function pdf(#[\SensitiveParameter] array $document): string;
    /** @param array<string, mixed> $document */
    public function html(#[\SensitiveParameter] array $document): string;
}
