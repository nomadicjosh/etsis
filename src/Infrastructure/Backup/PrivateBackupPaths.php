<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use RuntimeException;

/** Local operator paths: no public destinations, symbolic parents or permissive recovery secrets. */
final readonly class PrivateBackupPaths
{
    public function __construct(private string $publicDirectory)
    {
    }

    public function canonical(string $path): void
    {
        if (
            !str_starts_with($path, "/") ||
            strlen($path) > 4096 ||
            preg_match('/[\x00-\x1f\x7f]/', $path) === 1 ||
            preg_match('~(?:^|/)\.{1,2}(?:/|$)|//~', $path) === 1 ||
            rtrim($path, "/") !== $path
        ) {
            throw new RuntimeException("Use a canonical absolute local path.");
        }
        $current = $path;
        for ($depth = 0; $depth < 128; $depth++) {
            if (is_link($current)) {
                throw new RuntimeException(
                    "Symbolic backup paths are unavailable.",
                );
            }
            if ($current === "/") {
                return;
            }
            $current = dirname($current);
        }
        throw new RuntimeException("Backup path depth exceeds its allowance.");
    }

    public function directory(string $path): void
    {
        $this->canonical($path);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $public = realpath($this->publicDirectory);
        if (
            $stat === false ||
            ($stat["mode"] & 0170000) !== 0040000 ||
            ($stat["mode"] & 0077) !== 0 ||
            (function_exists("posix_geteuid") &&
                $stat["uid"] !== posix_geteuid()) ||
            !is_readable($path) ||
            !is_writable($path) ||
            !is_executable($path) ||
            $public === false ||
            $path === $public ||
            str_starts_with($path, $public . "/")
        ) {
            throw new RuntimeException(
                "Use an owned private directory outside public files.",
            );
        }
    }

    public function secret(string $path, int $maximum = 65536): string
    {
        $this->directory(dirname($path));
        $this->canonical($path);
        $stat = @lstat($path);
        if (
            $stat === false ||
            ($stat["mode"] & 0170000) !== 0100000 ||
            ($stat["mode"] & 0077) !== 0 ||
            (function_exists("posix_geteuid") &&
                $stat["uid"] !== posix_geteuid()) ||
            $stat["size"] > $maximum
        ) {
            throw new RuntimeException(
                "The private recovery file is unavailable.",
            );
        }
        $text = file_get_contents($path, false, null, 0, $maximum + 1);
        if ($text === false || strlen($text) > $maximum) {
            throw new RuntimeException(
                "The private recovery file exceeds its allowance.",
            );
        }
        return $text;
    }

    public function newFile(
        string $path,
        #[\SensitiveParameter] string $bytes,
    ): void {
        $this->directory(dirname($path));
        $this->canonical($path);
        $previous = umask(0077);
        try {
            $file = @fopen($path, "xb");
        } finally {
            umask($previous);
        }
        if ($file === false) {
            throw new RuntimeException(
                "Recovery output already exists or cannot be created.",
            );
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) {
                throw new RuntimeException(
                    "Recovery output could not be completed.",
                );
            }
        } finally {
            fclose($file);
        }
    }
}
