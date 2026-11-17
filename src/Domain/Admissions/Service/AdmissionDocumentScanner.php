<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

interface AdmissionDocumentScanner
{
    /** Return clean, infected or unavailable; never return upstream output. */
    public function scan(#[\SensitiveParameter] string $bytes): string;
}
