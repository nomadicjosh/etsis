<?php

declare(strict_types=1);

namespace Infrastructure\Operations;

use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\AccountProjectionStageStorage;
use Domain\Finance\ValueObject\AccountProjectionStage;
use Domain\Shared\ValueObject\EntityId;
use RuntimeException;

/** Disposable encrypted output outside the public tree; original journal facts stay in the database. */
final readonly class LocalAccountProjectionStageStorage implements AccountProjectionStageStorage
{
    public function __construct(
        private MessageCipher $cipher,
        private string $directory,
        private string $publicDirectory,
    ) {
    }

    private function path(EntityId $id, bool $create = false): string
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Invalid private projection directory.');
        }
        if (
            $create && !is_dir($this->directory) && !@mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException('Private projection storage is unavailable.');
        }
        clearstatcache(true, $this->directory);
        $real = realpath($this->directory);
        $public = realpath($this->publicDirectory);
        $stat = $real === false ? false : @stat($real);
        if (
            $real !== $this->directory || $public === false || $stat === false
            || $real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR)
            || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        ) {
            throw new RuntimeException('Use owned private projection storage outside public files.');
        }
        $path = $real . DIRECTORY_SEPARATOR . $id->value . '.stage';
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Invalid private projection stage.');
        }
        return $path;
    }

    public function write(AccountProjectionStage $stage): void
    {
        $path = $this->path(new EntityId($stage->data['id']), true);
        $content = $this->cipher->seal(json_encode($stage->data, JSON_THROW_ON_ERROR));
        if (strlen($content) > 16_777_216) {
            throw new RuntimeException('Projection stage exceeds its storage bound.');
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('Preserve the existing projection stage.');
        }
        $complete = false;
        try {
            if (
                !@chmod($path, 0600) || @fwrite($handle, $content) !== strlen($content) || !@fflush($handle)
                || (function_exists('fsync') && !@fsync($handle))
            ) {
                throw new RuntimeException('The private projection stage could not be saved.');
            }
            $complete = true;
        } finally {
            @fclose($handle);
            if (!$complete) {
                @unlink($path);
            }
        }
    }

    public function read(EntityId $id): AccountProjectionStage
    {
        $path = $this->path($id);
        $stat = @lstat($path);
        if (
            $stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        ) {
            throw new RuntimeException('Private projection stage unavailable.');
        }
        $content = @file_get_contents($path, false, null, 0, 16_777_217);
        if ($content === false || $content === '' || strlen($content) > 16_777_216) {
            throw new RuntimeException('Private projection stage exceeds its read bound.');
        }
        $plain = $this->cipher->open($content);
        if (strlen($plain) > 8_388_608) {
            throw new RuntimeException('Private projection output exceeds its read bound.');
        }
        $stage = new AccountProjectionStage(json_decode($plain, true, 32, JSON_THROW_ON_ERROR));
        if ($stage->data['id'] !== $id->value) {
            throw new RuntimeException('Private projection stage identity mismatch.');
        }
        return $stage;
    }

    public function discard(EntityId $id): void
    {
        $path = $this->path($id);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (
            ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || !@unlink($path)
        ) {
            throw new RuntimeException('The private projection stage could not be discarded.');
        }
    }

    public function __debugInfo(): array
    {
        return ['storage' => '[private projection stage storage]'];
    }
}
