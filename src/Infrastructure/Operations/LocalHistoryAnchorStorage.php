<?php

declare(strict_types=1);

namespace Infrastructure\Operations;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Key;
use Domain\Shared\EventSourcing\HistoryAnchor;
use Domain\Shared\EventSourcing\HistoryAnchorStorage;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use JsonException;
use Qubus\Config\ConfigContainer;
use RuntimeException;
use Throwable;

/** Exclusive private artifacts protected by a key independent of application/event encryption. */
final readonly class LocalHistoryAnchorStorage implements HistoryAnchorStorage
{
    public function __construct(private ConfigContainer $config, private string $publicDirectory)
    {
    }

    /**
     * @throws EnvironmentIsBrokenException
     */
    public function generateKey(string $keyPath): void
    {
        $this->create($keyPath, Key::createNewRandomKey()->saveToAsciiSafeString() . "\n");
    }

    /**
     * @throws EnvironmentIsBrokenException
     * @throws JsonException
     */
    public function write(string $path, string $keyPath, HistoryAnchor $anchor): void
    {
        $key = $this->key($keyPath);
        $content = ProtectedEventCodec::canonical(['purpose' => 'history.anchor.v1', 'data' => $anchor->data]);
        $this->create($path, Crypto::encrypt($content, $key));
    }

    public function read(string $path, string $keyPath): HistoryAnchor
    {
        try {
            $key = $this->key($keyPath);
            $data = json_decode(Crypto::decrypt($this->load($path, 16384), $key), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 2 || ($data['purpose'] ?? null) !== 'history.anchor.v1') {
                throw new RuntimeException();
            }
            return new HistoryAnchor($data['data']);
        } catch (Throwable) {
            throw new RuntimeException('The private checkpoint or independent key could not be validated.');
        }
    }

    private function key(string $path): Key
    {
        try {
            $key = Key::loadFromAsciiSafeString(trim($this->load($path, 4096)));
            $encoded = $key->saveToAsciiSafeString();
            $keys = $this->config->getConfigKey('event_history.keys') ?? [];
            if (!is_array($keys)) {
                throw new RuntimeException();
            }
            $keys[] = $this->config->getConfigKey('app.crypto_key');
            foreach ($keys as $existing) {
                if (is_string($existing) && $existing !== '') {
                    $normalized = Key::loadFromAsciiSafeString($existing)->saveToAsciiSafeString();
                    if (hash_equals($encoded, $normalized)) {
                        throw new RuntimeException();
                    }
                }
            }
            return $key;
        } catch (Throwable) {
            throw new RuntimeException('Use a private checkpoint key separate from application/event keys.');
        }
    }

    private function path(string $path): void
    {
        clearstatcache(true, $path);
        clearstatcache(true, dirname($path));
        $directory = realpath(dirname($path));
        $public = realpath($this->publicDirectory);
        $stat = $directory === false ? false : @stat($directory);
        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR) || $directory === false ||
            $public === false || $stat === false ||
            $directory !== dirname($path) || $directory === $public ||
            str_starts_with($directory, $public . DIRECTORY_SEPARATOR) || is_link($path) ||
            ($stat['mode'] & 0077) !== 0 ||
            (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        ) {
            throw new RuntimeException('Use an absolute path in an owned private directory outside the public tree.');
        }
    }

    private function load(string $path, int $limit): string
    {
        $this->path($path);
        $stat = @lstat($path);
        if (
            $stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0 ||
            (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        ) {
            throw new RuntimeException('The private checkpoint file could not be read.');
        }
        $content = @file_get_contents($path, false, null, 0, $limit + 1);
        if ($content === false || $content === '' || strlen($content) > $limit) {
            throw new RuntimeException('The private checkpoint file is unavailable or exceeds its bound.');
        }
        return $content;
    }

    private function create(string $path, #[\SensitiveParameter] string $content): void
    {
        $this->path($path);
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('Use a new writable private destination; existing files are preserved.');
        }
        $complete = false;
        try {
            if (!@chmod($path, 0600) || @fwrite($handle, $content) !== strlen($content) || !@fflush($handle)) {
                throw new RuntimeException('The private artifact could not be written completely.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new RuntimeException('The private artifact could not be synchronized.');
            }
            $complete = true;
        } finally {
            @fclose($handle);
            if (!$complete) {
                @unlink($path);
            }
        }
    }

    public function __debugInfo(): array
    {
        return ['storage' => '[private checkpoint storage]'];
    }
}
