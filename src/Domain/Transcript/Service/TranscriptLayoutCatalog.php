<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Transcript\ValueObject\TranscriptLayout;

/** Deployment-registered layouts, never browser-supplied paths, PHP, CSS or remote resources. */
interface TranscriptLayoutCatalog
{
    /** Null deliberately selects the institution's configured default. */
    public function get(?string $code = null): TranscriptLayout;
    /** @return list<TranscriptLayout> */
    public function available(): array;
}
