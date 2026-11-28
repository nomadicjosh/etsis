<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Domain\Shared\Backup\BackupBudget;
use Domain\Shared\Backup\BackupEngine;
use Domain\Shared\Backup\BackupManifest;
use RuntimeException;

final readonly class ResticBackupEngine implements BackupEngine
{
    public function __construct(
        private BackupConfiguration $config,
        private ResticProcess $process,
        private NativeDatabaseSource $source,
        private PrivateBackupPaths $paths,
    ) {
    }

    public function initialize(BackupBudget $budget): void
    {
        $this->process->run(["init", "--repository-version=2"], $budget);
    }

    public function driver(): string
    {
        return $this->source->driver();
    }

    private function options(string $bundle, string $part): array
    {
        return [
            "backup",
            "--read-concurrency=1",
            "--tag=etsis",
            "--tag=bundle:" . $bundle,
            "--tag=part:" . $part,
            "--host=etsis-backup",
            "--compression=off",
            "--pack-size=4",
        ];
    }

    private function snapshot(string $output): string
    {
        $id = null;
        foreach (explode("\n", trim($output)) as $line) {
            $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (
                ($data["message_type"] ?? "") === "summary" &&
                isset($data["snapshot_id"])
            ) {
                if ($id !== null) {
                    throw new RuntimeException("Ambiguous backup completion.");
                }
                $id = $data["snapshot_id"];
            }
        }
        BackupManifest::snapshot($id ?? "");
        return $id;
    }

    public function database(string $bundle, BackupBudget $budget): string
    {
        $export = $this->source->export();
        return $this->snapshot(
            $this->process->run(
                [
                    ...$this->options($bundle, "database"),
                    "--stdin-filename=/database.dump",
                    "--stdin-from-command",
                    "--",
                    ...$export["command"],
                ],
                $budget,
                environment: $export["environment"],
            ),
        );
    }

    public function files(
        string $bundle,
        array $paths,
        BackupBudget $budget,
    ): string {
        $list = $this->config->workDirectory . "/" . $bundle . ".paths";
        $this->paths->newFile($list, implode("\n", $paths) . "\n");
        try {
            return $this->snapshot(
                $this->process->run(
                    [
                        ...$this->options($bundle, "files"),
                        "--files-from-verbatim=" . $list,
                    ],
                    $budget,
                ),
            );
        } finally {
            unlink($list);
        }
    }

    public function databaseDigest(
        string $snapshot,
        BackupBudget $budget,
    ): array {
        BackupManifest::snapshot($snapshot);
        $hash = hash_init("sha256");
        $bytes = 0;
        $this->process->run(
            ["dump", $snapshot, "/database.dump"],
            $budget,
            static function (#[\SensitiveParameter] string $chunk) use (
                $hash,
                &$bytes,
                $budget,
            ): void {
                $bytes += strlen($chunk);
                if ($bytes > $budget->bytes) {
                    throw new RuntimeException(
                        "The database export exceeds its allowance.",
                    );
                }
                hash_update($hash, $chunk);
            },
        );
        if ($bytes === 0) {
            throw new RuntimeException(
                "An empty database export cannot complete a backup.",
            );
        }
        return ["bytes" => $bytes, "sha256" => hash_final($hash)];
    }

    public function commit(
        BackupManifest $manifest,
        BackupBudget $budget,
    ): string {
        $text = json_encode(
            $manifest->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($text) > 8 * 1024 * 1024) {
            throw new RuntimeException(
                "The backup manifest exceeds its allowance.",
            );
        }
        return $this->snapshot(
            $this->process->run(
                [
                    ...$this->options($manifest->data["id"], "complete"),
                    "--stdin-filename=/bundle.json",
                    "--stdin",
                ],
                $budget,
                input: $text,
            ),
        );
    }

    public function read(string $snapshot, BackupBudget $budget): BackupManifest
    {
        BackupManifest::snapshot($snapshot);
        return new BackupManifest(
            json_decode(
                $this->process->run(
                    ["dump", $snapshot, "/bundle.json"],
                    $budget,
                ),
                true,
                24,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function restore(
        BackupManifest $manifest,
        string $destination,
        BackupBudget $budget,
    ): void {
        $nodes = [];
        $lines = explode("\n", trim($this->process->run(["ls", $manifest->data["files"]], $budget)));
        foreach ($lines as $line) {
            $node = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (($node["struct_type"] ?? null) === "snapshot") {
                continue;
            }
            if (($node["type"] ?? null) === "dir") {
                continue;
            }
            if (
                ($node["type"] ?? null) !== "file" ||
                !is_string($node["path"] ?? null) ||
                isset($nodes[$node["path"]])
            ) {
                throw new RuntimeException(
                    "Unexpected backup file type or duplicate path.",
                );
            }
            $nodes[$node["path"]] = $node["size"];
            if (
                count($nodes) > $budget->files ||
                array_sum($nodes) > $budget->bytes
            ) {
                throw new RuntimeException(
                    "The restore inventory exceeds its allowance.",
                );
            }
        }
        $expected = array_column($manifest->data["inventory"], "bytes", "path");
        ksort($nodes, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($nodes !== $expected) {
            throw new RuntimeException(
                "The selected files snapshot does not match the manifest.",
            );
        }
        $this->process->run(
            [
                "restore",
                $manifest->data["files"],
                "--target=" . $destination . "/files",
                "--verify",
            ],
            $budget,
        );
        $path = $destination . "/database.dump";
        $this->paths->newFile($path, "");
        $file = fopen($path, "r+b");
        $bytes = 0;
        try {
            $this->process->run(
                ["dump", $manifest->data["database"], "/database.dump"],
                $budget,
                static function (#[\SensitiveParameter] string $chunk) use (
                    $file,
                    &$bytes,
                    $budget,
                ): void {
                    $bytes += strlen($chunk);
                    if (
                        $bytes > $budget->bytes ||
                        fwrite($file, $chunk) !== strlen($chunk)
                    ) {
                        throw new RuntimeException(
                            "The database restore exceeded its allowance.",
                        );
                    }
                },
            );
        } finally {
            fclose($file);
        }
    }

    public function recent(BackupBudget $budget): array
    {
        $rows = json_decode(
            $this->process->run(
                ["snapshots", "--tag=etsis,part:complete", "--latest=20"],
                $budget,
            ),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $result = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            BackupManifest::snapshot($row["id"]);
            $result[] = ["snapshot" => $row["id"], "time" => $row["time"]];
        }
        return $result;
    }
}
