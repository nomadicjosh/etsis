<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\Exception\ValidationException;

final readonly class WaitlistPolicy
{
    public function __construct(public bool $enabled, public int $maximumActive)
    {
        if ($maximumActive < 1 || $maximumActive > 100) {
            throw new ValidationException([
                [
                    "field" => "maximum_active",
                    "message" => "Use a waitlist limit from one through one hundred.",
                ],
            ]);
        }
    }
    public static function from(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        if (
            $keys !== ["allocation", "enabled", "maximum_active", "ordering"] ||
            !is_bool($data["enabled"]) ||
            !is_int($data["maximum_active"]) ||
            $data["ordering"] !== "accepted_fifo" ||
            $data["allocation"] !== "oldest_may_register"
        ) {
            throw new ValidationException([
                [
                    "field" => "policy",
                    "message" => "Select first-accepted ordering and oldest-request registration.",
                ],
            ]);
        }
        return new self($data["enabled"], $data["maximum_active"]);
    }
    public function describe(): array
    {
        return [
            "enabled" => $this->enabled,
            "maximum_active" => $this->maximumActive,
            "ordering" => "accepted_fifo",
            "allocation" => "oldest_may_register",
        ];
    }
    public static function hash(array $data): string
    {
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map(
                static fn(mixed $v): mixed => is_array($v) ? $canonical($v) : $v,
                $value,
            );
        };
        return hash(
            "sha256",
            json_encode($canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
