<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use InvalidArgumentException;

final readonly class MappedImportService
{
    public function __construct(
        private CourseCsvMapper $mapper,
        private CatalogImportService $importer,
        private StudentAccess $access
    ) {
    }

    /**
     * @param array<string, int> $mapping
     * @return array<string, mixed>
     */
    public function preview(string $csv, array $mapping, string $delimiter = ','): array
    {
        $this->access->requirePermission('sis:import:manage');
        $result = $this->mapper->prepare($csv, $mapping, $delimiter);
        unset($result['canonical_csv']);

        return $result;
    }

    /** @param array<string, int> $mapping */
    public function commit(
        string $source,
        string $csv,
        array $mapping,
        string $fingerprint,
        string $delimiter = ','
    ): int {
        $this->access->requirePermission('sis:import:manage');
        $result = $this->mapper->prepare($csv, $mapping, $delimiter);
        if (!$result['ready']) {
            throw new ValidationException($result['errors']);
        }
        if (!hash_equals($result['fingerprint'], $fingerprint)) {
            throw new InvalidArgumentException('The import changed. Review the new preview before importing.');
        }

        return $this->importer->courses($source, $result['canonical_csv']);
    }
}
