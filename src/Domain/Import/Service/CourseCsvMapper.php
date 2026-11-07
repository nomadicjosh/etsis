<?php

declare(strict_types=1);

namespace Domain\Import\Service;

interface CourseCsvMapper
{
    /**
     * @param array<string, int> $mapping
     * @return array<string, mixed>
     */
    public function prepare(#[\SensitiveParameter] string $csv, array $mapping, string $delimiter): array;
}
