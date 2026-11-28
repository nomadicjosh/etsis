<?php

declare(strict_types=1);

namespace Domain\ServiceFee\ValueObject;

use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class FeeSchema
{
    public const array FIELDS = [
        "operation_id",
        "previous_id",
        "action",
        "details",
        "intent",
    ];
    public static function keys(array $p, array $keys): void
    {
        if (
            count($p) !== count($keys) ||
            array_diff(array_keys($p), $keys) !== []
        ) {
            throw new CorruptHistoryException();
        }
    }
    public static function hash(mixed $value): string
    {
        return hash(
            "sha256",
            json_encode(
                is_array($value) ? self::canonical($value) : $value,
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }
    private static function text(
        mixed $value,
        int $maximum,
        bool $empty = false,
    ): void {
        if (
            !is_string($value) ||
            !mb_check_encoding($value, "UTF-8") ||
            mb_strlen($value) > $maximum ||
            trim($value) !== $value ||
            (!$empty && $value === "") ||
            preg_match('/[\x00-\x1f\x7f]/', $value)
        ) {
            throw new CorruptHistoryException();
        }
    }
    public static function validate(array $p): void
    {
        self::keys($p, self::FIELDS);
        new EntityId($p["operation_id"]);
        if ($p["previous_id"] !== null) {
            new EntityId($p["previous_id"]);
        }
        if (
            !is_string($p["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["intent"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        $d = $p["details"];
        if (!is_array($d)) {
            throw new CorruptHistoryException();
        }
        switch ($p["action"]) {
            case 'capture_reserve':
                \Domain\Payments\ValueObject\CaptureState::plan($d);
                break;
            case 'capture_progress':
                \Domain\Payments\ValueObject\CaptureState::progress($d);
                if ($d['state'] === 'paid') {
                    throw new CorruptHistoryException();
                }
                break;
            case "assess":
                self::keys($d, [
                    "person_id",
                    "owner_id",
                    "purpose",
                    "target",
                    "labels",
                    "policy",
                    ...(array_key_exists('accounting', $d) ? ['accounting'] : []),
                ]);
                FeeAccounting::snapshot($d['accounting'] ?? null);
                new EntityId($d["person_id"]);
                new EntityId($d["owner_id"]);
                self::purpose($d["purpose"]);
                self::target($d["purpose"], $d["target"]);
                FeePolicy::from($d["policy"]);
                self::keys($d["labels"], ["title", "term"]);
                self::text($d["labels"]["title"], 300);
                self::text($d["labels"]["term"], 100, true);
                break;
            case "payment":
                self::keys($d, [
                    "payment_id",
                    "minor",
                    "currency",
                    "channel",
                    "method",
                    "reference",
                    "session",
                    ...(array_key_exists('asset', $d) ? ['asset'] : []),
                ]);
                if (array_key_exists('asset', $d)) {
                    \Domain\Finance\ValueObject\LedgerSchema::account($d['asset']);
                }
                new EntityId($d["payment_id"]);
                self::amount($d["minor"]);
                new \Domain\Finance\ValueObject\Money(
                    $d["minor"],
                    $d["currency"],
                );
                PaymentDetails::fromRecorded([
                    "method" => $d["method"],
                    "reference" => $d["reference"],
                ]);
                if (
                    !in_array($d["channel"], ["provider", "staff"], true) ||
                    ($d["channel"] === "provider"
                        ? !is_string($d["session"]) ||
                            preg_match(
                                '/^cs_[A-Za-z0-9_]{1,180}$/D',
                                $d["session"],
                            ) !== 1
                        : $d["session"] !== null)
                ) {
                    throw new CorruptHistoryException();
                }
                break;
            case "waive":
                self::keys($d, []);
                break;
            case "apply":
                self::keys($d, ["target_id"]);
                new EntityId($d["target_id"]);
                break;
            case "refund":
                self::keys($d, ["payment_id", "minor", "reference"]);
                new EntityId($d["payment_id"]);
                self::amount($d["minor"]);
                self::text($d["reference"], 120);
                break;
            default:
                throw new CorruptHistoryException();
        }
    }
    public static function purpose(string $kind): void
    {
        if (!in_array($kind, ["application", "transcript"], true)) {
            throw new \InvalidArgumentException(
                "Choose an application or official transcript fee.",
            );
        }
    }
    public static function target(string $purpose, array $target): void
    {
        self::keys(
            $target,
            $purpose === "application"
                ? ["program_id", "term_id"]
                : ["student_id"],
        );
        foreach ($target as $value) {
            new EntityId($value);
        }
    }
    public static function amount(mixed $minor): void
    {
        if (!is_int($minor) || $minor < 1 || $minor > 2000000000) {
            throw new \InvalidArgumentException(
                "Enter a positive amount in the fee currency.",
            );
        }
    }
}
