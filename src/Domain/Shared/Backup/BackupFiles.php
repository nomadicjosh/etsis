<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

interface BackupFiles
{
    public function exclusively(callable $operation): mixed;

    /** @return list<array{path:string,bytes:int,sha256:string}> */
    public function inventory(array $includes, BackupBudget $budget): array;

    public function createDestination(string $path): void;

    public function verifyRestore(
        string $destination,
        BackupManifest $manifest,
        BackupBudget $budget,
    ): void;

    public function markRestore(
        string $destination,
        string $snapshot,
        string $bundle,
    ): void;
}
