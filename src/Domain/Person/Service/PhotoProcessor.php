<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\ValueObject\ProfilePhoto;

interface PhotoProcessor
{
    public function normalize(
        #[\SensitiveParameter] string $bytes,
        string $filename,
    ): ProfilePhoto;
}
