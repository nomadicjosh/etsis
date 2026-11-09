<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Key;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use JsonException;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Data\TypeException;
use Throwable;

/** Authenticated, purpose-bound envelopes. Key identifiers are public; keys never enter the database. */
final readonly class ProtectedEventCodec
{
    public function __construct(private ConfigContainer $config)
    {
    }

    /** @param array<string, mixed> $data */
    public function seal(string $purpose, #[\SensitiveParameter] array $data): string
    {
        try {
            $id = $this->config->getConfigKey("event_history.active_key_id") ?? "app-v1";
            if (!is_string($id)) {
                throw new CorruptHistoryException();
            }
            $content = self::canonical(["format" => 1, "purpose" => $purpose, "data" => $data]);
            return self::canonical([
                "format" => 1,
                "key_id" => $id,
                "ciphertext" => Crypto::encrypt($content, $this->key($id)),
            ]);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string, mixed> */
    public function open(string $purpose, #[\SensitiveParameter] string $sealed): array
    {
        try {
            if (strlen($sealed) > 1048576) {
                throw new CorruptHistoryException();
            }
            $outer = json_decode($sealed, true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($outer) ||
                count($outer) !== 3 ||
                ($outer["format"] ?? null) !== 1 ||
                !is_string($outer["key_id"] ?? null) ||
                !is_string($outer["ciphertext"] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $value = json_decode(
                Crypto::decrypt($outer["ciphertext"], $this->key($outer["key_id"])),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($value) ||
                ($value["format"] ?? null) !== 1 ||
                ($value["purpose"] ?? null) !== $purpose ||
                !is_array($value["data"] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            return $value["data"];
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /**
     * @throws TypeException
     * @throws EnvironmentIsBrokenException
     * @throws BadFormatException
     */
    private function key(string $id): Key
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $id) !== 1) {
            throw new CorruptHistoryException();
        }
        $keys = $this->config->getConfigKey("event_history.keys") ?? [];
        if (!is_array($keys)) {
            throw new CorruptHistoryException();
        }
        // Only an explicitly empty keyring permits the application-key starting configuration.
        $encoded =
        $keys === [] && $id === "app-v1" ? $this->config->getConfigKey("app.crypto_key") : $keys[$id] ?? null;
        if (!is_string($encoded) || $encoded === "") {
            throw new CorruptHistoryException();
        }
        return Key::loadFromAsciiSafeString($encoded);
    }

    /**
     * @param array<mixed> $value
     * @throws JsonException
     */
    public static function canonical(#[\SensitiveParameter] array $value): string
    {
        $normalized = json_decode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        return json_encode(
            self::sort($normalized),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sort(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }
        return $value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ["keys" => "[protected event keyring]"];
    }
}
