<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    "active_key_id" => env("EVENT_HISTORY_ACTIVE_KEY_ID", "app-v1"),
    // An unset file uses app.crypto_key under the stable app-v1 identifier.
    // A configured rotation keyring is a private JSON file outside public/ and version control.
    "keys" => (static function (): array {
        $path = env("EVENT_HISTORY_KEYS_FILE");
        if ($path === null || $path === "") {
            return [];
        }
        try {
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException();
            }
            $content = @file_get_contents($path, false, null, 0, 65537);
            if ($content === false || strlen($content) > 65536) {
                throw new RuntimeException();
            }
            $keys = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($keys) || $keys === [] || array_is_list($keys)) {
                throw new RuntimeException();
            }
            return $keys;
        } catch (Throwable) {
            throw new RuntimeException("Historical event keyring configuration could not be read.");
        }
    })(),
];
