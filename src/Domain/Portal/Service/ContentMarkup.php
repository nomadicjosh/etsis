<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

interface ContentMarkup
{
    public function sanitize(#[\SensitiveParameter] string $html): string;
    public function plainText(string $sanitizedHtml): string;
}
