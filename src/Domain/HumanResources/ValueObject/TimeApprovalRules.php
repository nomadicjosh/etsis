<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use DateTimeZone;
use Domain\Shared\Exception\ValidationException;

/** Institution-selected review thresholds, never wage, overtime or statutory rules. */
final readonly class TimeApprovalRules implements \JsonSerializable
{
    public array $data;
    public function __construct(#[\SensitiveParameter] array $data)
    {
        $valid = array_keys($data);
        if (array_key_exists('require_primary_position', $data)) {
            if (!is_bool($data['require_primary_position'])) {
                self::invalid();
            }
            $valid = array_values(array_diff($valid, ['require_primary_position']));
        }
        sort($valid);
        if (
            $valid !== [
                "contact_rounding",
                "contact_unit_minutes",
                "daily_minutes",
                "mode",
                "overlaps",
                "timezone",
                "weekly_minutes",
            ] ||
            !in_array($data["mode"], ["advisory", "exception_required"], true) ||
            !is_bool($data["overlaps"]) ||
            !is_string($data["timezone"])
        ) {
            self::invalid();
        }
        try {
            new DateTimeZone($data["timezone"]);
        } catch (\Throwable) {
            self::invalid();
        }
        foreach (["daily_minutes" => 1500, "weekly_minutes" => 10140] as $key => $max) {
            if (
                $data[$key] !== null &&
                (!is_int($data[$key]) || $data[$key] < 1 || $data[$key] > $max)
            ) {
                self::invalid();
            }
        }
        if (
            !is_int($data["contact_unit_minutes"]) ||
            $data["contact_unit_minutes"] < 1 ||
            $data["contact_unit_minutes"] > 120 ||
            !in_array($data["contact_rounding"], ["nearest", "down", "up"], true)
        ) {
            self::invalid();
        }
        $this->data = [
            "contact_unit_minutes" => $data["contact_unit_minutes"],
            "contact_rounding" => $data["contact_rounding"],
            "daily_minutes" => $data["daily_minutes"],
            "weekly_minutes" => $data["weekly_minutes"],
            "overlaps" => $data["overlaps"],
            "mode" => $data["mode"],
            "timezone" => $data["timezone"],
            ...array_key_exists('require_primary_position', $data)
                ? ['require_primary_position' => $data['require_primary_position']] : [],
        ];
    }
    public static function defaults(DateTimeZone $zone): self
    {
        return new self([
            "contact_unit_minutes" => 50,
            "contact_rounding" => "nearest",
            "daily_minutes" => null,
            "weekly_minutes" => null,
            "overlaps" => false,
            "mode" => "advisory",
            "timezone" => $zone->getName(),
            'require_primary_position' => false,
        ]);
    }
    public function requiresPrimaryPosition(): bool
    {
        return $this->data['require_primary_position'] ?? false;
    }

    public function hasChecks(): bool
    {
        return $this->data["overlaps"] ||
        $this->data["daily_minutes"] !== null ||
        $this->data["weekly_minutes"] !== null;
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
            json_encode(
                $canonical($data),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "rules",
                "message" =>
                    "Choose advisory or exception approval, optional positive daily/weekly limits, " .
                    "overlap checking and a valid timezone. Contact units require 1–120 minutes " .
                    "and nearest/down/up rounding.",
            ],
        ]);
    }
    public function jsonSerialize(): array
    {
        return ["rules" => "[institutional time-review rules]"];
    }
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
