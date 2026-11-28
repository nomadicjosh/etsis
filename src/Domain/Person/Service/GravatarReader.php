<?php

declare(strict_types=1);

namespace Domain\Person\Service;

interface GravatarReader
{
    /** Fixed upstream, explicit consent, no arbitrary URL or user-supplied email. */
    public function image(#[\SensitiveParameter] string $email): string;
}
