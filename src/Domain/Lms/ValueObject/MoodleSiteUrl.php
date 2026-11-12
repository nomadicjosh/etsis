<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use InvalidArgumentException;

/** Canonical HTTPS installation address, not a permanent Moodle-instance identifier. */
final readonly class MoodleSiteUrl
{
    public string $value;

    public function __construct(#[\SensitiveParameter] string $url)
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new InvalidArgumentException("Use a valid HTTPS Moodle site base URL.");
        }
        $parts = parse_url($url);
        if (
            $parts === false ||
            strtolower($parts["scheme"] ?? "") !== "https" ||
            empty($parts["host"]) ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            isset($parts["query"]) ||
            isset($parts["fragment"]) ||
            (isset($parts["port"]) && $parts["port"] < 1)
        ) {
            throw new InvalidArgumentException("Use a valid HTTPS Moodle site base URL.");
        }
        $path = rtrim($parts["path"] ?? "", "/");
        foreach (explode("/", $path) as $segment) {
            if (in_array(strtolower(rawurldecode($segment)), [".", ".."], true)) {
                throw new InvalidArgumentException("Use a Moodle site URL without relative path segments.");
            }
        }
        $port = isset($parts["port"]) && $parts["port"] !== 443 ? ":" . $parts["port"] : "";
        $this->value = "https://" . strtolower($parts["host"]) . $port . $path;
    }
    public function matches(self $other): bool
    {
        return $this->value === $other->value;
    }
}
