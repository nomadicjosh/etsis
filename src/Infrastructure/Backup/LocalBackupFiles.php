<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Domain\Shared\Backup\BackupBudget;
use Domain\Shared\Backup\BackupFiles;
use Domain\Shared\Backup\BackupManifest;
use RuntimeException;

final readonly class LocalBackupFiles implements BackupFiles
{
    public function __construct(
        private PrivateBackupPaths $paths,
        private string $workDirectory,
    ) {
    }

    public function exclusively(callable $operation): mixed
    {
        $this->paths->directory($this->workDirectory);
        $path = $this->workDirectory . "/operation.lock";
        $this->paths->canonical($path);
        if (
            file_exists($path) &&
            (!is_file($path) || (fileperms($path) & 0077) !== 0)
        ) {
            throw new RuntimeException(
                "The backup operation lock is unavailable.",
            );
        }
        $previous = umask(0077);
        try {
            $lock = fopen($path, "c+b");
        } finally {
            umask($previous);
        }
        if ($lock === false) {
            throw new RuntimeException(
                "The backup operation lock is unavailable.",
            );
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException(
                    "Another backup operation is running.",
                );
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function inventory(array $includes, BackupBudget $budget): array
    {
        $stack = array_values($includes);
        $files = [];
        $bytes = 0;
        $nodes = 0;
        while ($stack !== []) {
            $budget->remaining();
            if (++$nodes > $budget->files * 8 + 128) {
                throw new RuntimeException(
                    "The file inventory exceeds its allowance.",
                );
            }
            $path = array_pop($stack);
            $this->paths->canonical($path);
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) {
                throw new RuntimeException("A backup source disappeared.");
            }
            if (($stat["mode"] & 0170000) === 0040000) {
                $directory = new \FilesystemIterator(
                    $path,
                    \FilesystemIterator::SKIP_DOTS,
                );
                foreach ($directory as $child) {
                    if (count($stack) + $nodes > $budget->files * 8 + 128) {
                        throw new RuntimeException(
                            "The file inventory exceeds its allowance.",
                        );
                    }
                    $stack[] = $child->getPathname();
                }
            } elseif (($stat["mode"] & 0170000) === 0100000) {
                if (isset($files[$path])) {
                    continue;
                }
                $bytes += $stat["size"];
                if (
                    count($files) >= $budget->files ||
                    $bytes > $budget->bytes
                ) {
                    throw new RuntimeException(
                        "The file inventory exceeds its allowance.",
                    );
                }
                $files[$path] = [
                    "path" => $path,
                    ...$this->digest($path, $budget),
                ];
            } else {
                throw new RuntimeException(
                    "Only ordinary local files and directories can be backed up.",
                );
            }
        }
        ksort($files, SORT_STRING);
        if ($files === []) {
            throw new RuntimeException(
                "An empty file inventory cannot complete a backup.",
            );
        }
        return array_values($files);
    }

    private function digest(string $path, BackupBudget $budget): array
    {
        $this->paths->canonical($path);
        $before = lstat($path);
        $file = fopen($path, "rb");
        if ($file === false) {
            throw new RuntimeException("A backup source could not be read.");
        }
        try {
            $stat = fstat($file);
            if (
                $stat["ino"] !== $before["ino"] ||
                $stat["dev"] !== $before["dev"] ||
                ($stat["mode"] & 0170000) !== 0100000 ||
                $stat["size"] > $budget->bytes
            ) {
                throw new RuntimeException(
                    "A backup source changed while opening.",
                );
            }
            $hash = hash_init("sha256");
            $count = 0;
            while (!feof($file)) {
                $budget->remaining();
                $chunk = fread($file, 131072);
                if ($chunk === false) {
                    throw new RuntimeException(
                        "A backup source could not be read.",
                    );
                }
                $count += strlen($chunk);
                if ($count > $budget->bytes) {
                    throw new RuntimeException(
                        "A backup source exceeds its allowance.",
                    );
                }
                hash_update($hash, $chunk);
            }
            $after = fstat($file);
            $fields = ["dev", "ino", "mode", "size", "mtime", "ctime"];
            if (
                array_any(
                    $fields,
                    static fn(string $field): bool => $after[$field] !==
                        $stat[$field],
                ) ||
                $count !== $stat["size"]
            ) {
                throw new RuntimeException(
                    "A backup source changed while reading.",
                );
            }
            return ["bytes" => $count, "sha256" => hash_final($hash)];
        } finally {
            fclose($file);
        }
    }

    public function createDestination(string $path): void
    {
        $this->paths->canonical($path);
        $this->paths->directory(dirname($path));
        if (file_exists($path) || !mkdir($path, 0700)) {
            throw new RuntimeException(
                "Restore requires a new directory in a private recovery location.",
            );
        }
        $this->paths->newFile(
            $path . "/incomplete.json",
            "{\"status\":\"incomplete\",\"activated\":false}\n",
        );
    }

    public function verifyRestore(
        string $destination,
        BackupManifest $manifest,
        BackupBudget $budget,
    ): void {
        $this->paths->directory($destination);
        $expected = [];
        foreach ($manifest->data["inventory"] as $file) {
            $expected[$destination . "/files" . $file["path"]] = $file;
        }
        $actual = $this->inventory([$destination . "/files"], $budget);
        if (count($actual) !== count($expected)) {
            throw new RuntimeException(
                "The restored file inventory does not match.",
            );
        }
        foreach ($actual as $file) {
            $original = $expected[$file["path"]] ?? null;
            if (
                $original === null ||
                $original["bytes"] !== $file["bytes"] ||
                !hash_equals($original["sha256"], $file["sha256"])
            ) {
                throw new RuntimeException(
                    "A restored private artifact does not match.",
                );
            }
            chmod($file["path"], 0600);
        }
        $database = $this->digest($destination . "/database.dump", $budget);
        if (
            $database["bytes"] !==
                $manifest->data["database_digest"]["bytes"] ||
            !hash_equals(
                $database["sha256"],
                $manifest->data["database_digest"]["sha256"],
            )
        ) {
            throw new RuntimeException(
                "The restored database export does not match.",
            );
        }
        chmod($destination . "/database.dump", 0600);
    }

    public function markRestore(
        string $destination,
        string $snapshot,
        string $bundle,
    ): void {
        $this->paths->newFile(
            $destination . "/verified.json",
            json_encode(
                [
                    "status" => "verified",
                    "snapshot" => $snapshot,
                    "bundle" => $bundle,
                    "database_changed" => false,
                    "activated" => false,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        unlink($destination . "/incomplete.json");
    }
}
