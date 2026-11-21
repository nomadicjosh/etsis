<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

interface ContactHoursPolicyReader
{
    /** Internal policy only, caller authorizes the section context. */
    public function current(): array;
}
