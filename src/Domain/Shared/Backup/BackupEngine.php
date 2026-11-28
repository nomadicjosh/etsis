<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

interface BackupEngine
{
    public function initialize(BackupBudget $budget): void;

    public function driver(): string;

    public function database(string $bundle, BackupBudget $budget): string;

    /** @param list<string> $paths */
    public function files(
        string $bundle,
        array $paths,
        BackupBudget $budget,
    ): string;

    /** @return array{sha256:string,bytes:int} */
    public function databaseDigest(
        string $snapshot,
        BackupBudget $budget,
    ): array;

    public function commit(
        BackupManifest $manifest,
        BackupBudget $budget,
    ): string;

    public function read(
        string $snapshot,
        BackupBudget $budget,
    ): BackupManifest;

    public function restore(
        BackupManifest $manifest,
        string $destination,
        BackupBudget $budget,
    ): void;

    /** @return list<array{snapshot:string,time:string}> */
    public function recent(BackupBudget $budget): array;
}
