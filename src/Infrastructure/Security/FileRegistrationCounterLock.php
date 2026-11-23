<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Application\Http\Security\RegistrationCounterLock;
use InvalidArgumentException;
use RuntimeException;

/** Stable lock stripes bound file creation independently of the number of public peers. */
final readonly class FileRegistrationCounterLock implements RegistrationCounterLock
{
    public function __construct(private string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0") || str_contains($directory, '://')) {
            throw new InvalidArgumentException('Registration requires a local lock directory.');
        }
    }

    public function run(string $identifier, callable $increment): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Registration requires an opaque counter identifier.');
        }
        clearstatcache();
        if (
            is_link($this->directory)
            || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))
            || !@chmod($this->directory, 0700)
        ) {
            throw new RuntimeException('Registration counter protection is unavailable.');
        }
        $path = $this->directory . '/' . substr($identifier, 0, 2) . '.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Registration counter protection is unavailable.');
        }
        $file = @fopen($path, 'cb');
        if ($file === false) {
            throw new RuntimeException('Registration counter protection is unavailable.');
        }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Registration counter protection is busy.');
            }
            try {
                clearstatcache();
                $opened = fstat($file);
                $current = @lstat($path);
                if (
                    $opened === false || $current === false
                    || ($current['mode'] & 0170000) !== 0100000
                    || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
                    || $current['nlink'] !== 1 || !@chmod($path, 0600)
                ) {
                    throw new RuntimeException('Registration counter protection is unavailable.');
                }
                $increment();
            } finally {
                flock($file, LOCK_UN);
            }
        } finally {
            fclose($file);
        }
        // Never unlink an active deployment's lock files: waiters must use the same inode.
    }
}
