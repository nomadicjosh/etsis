<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final class LedgerSchema
{
    public const string ZERO = "0000000000000000000000000000000000000000000000000000000000000000";

    public static function keys(array $data, array $keys): void
    {
        if (
            count($data) !== count($keys) ||
            array_diff(array_keys($data), $keys) !== []
        ) {
            throw new InvalidArgumentException("Invalid ledger definition.");
        }
    }

    public static function book(array $data): void
    {
        self::keys($data, [
            "currency",
            "timezone",
            "receivable",
            "charge",
            "payment",
            "refund",
            "charge_sources",
            "payment_methods",
            "periods",
            ...(array_key_exists('service_fees', $data) ? ['service_fees'] : []),
        ]);
        if (array_key_exists('service_fees', $data)) {
            \Domain\ServiceFee\ValueObject\FeeAccounting::mapping($data['service_fees']);
        }
        new Money(0, $data["currency"]);
        new DateTimeZone($data["timezone"]);
        if (
            !is_array($data["periods"]) ||
            !array_is_list($data["periods"]) ||
            count($data["periods"]) > 1000
        ) {
            throw new InvalidArgumentException(
                "The ledger period catalog exceeds its bound.",
            );
        }
        $seen = [];
        $lastEnd = null;
        foreach ($data["periods"] as $period) {
            self::keys($period, ["id", "starts_on", "ends_on"]);
            new EntityId($period["id"]);
            if (
                isset($seen[$period["id"]]) ||
                ($lastEnd !== null && $period["starts_on"] <= $lastEnd)
            ) {
                throw new InvalidArgumentException(
                    "Accounting periods must not overlap.",
                );
            }
            foreach (["starts_on", "ends_on"] as $field) {
                $date = DateTimeImmutable::createFromFormat(
                    "!Y-m-d",
                    $period[$field],
                );
                if (!$date || $date->format("Y-m-d") !== $period[$field]) {
                    throw new InvalidArgumentException(
                        "Invalid accounting period date.",
                    );
                }
            }
            if ($period["starts_on"] > $period["ends_on"]) {
                throw new InvalidArgumentException(
                    "The period end precedes its start.",
                );
            }
            $seen[$period["id"]] = true;
            $lastEnd = $period["ends_on"];
        }
        foreach (["receivable", "charge", "payment", "refund"] as $field) {
            self::account($data[$field]);
        }
        $mappingEnums = [
            "charge_sources" => ChargeSource::class,
            "payment_methods" => PaymentMethod::class,
        ];
        foreach ($mappingEnums as $key => $enum) {
            if (
                !is_array($data[$key]) ||
                count($data[$key]) > count($enum::cases())
            ) {
                throw new InvalidArgumentException("Invalid ledger mapping.");
            }
            foreach ($data[$key] as $name => $account) {
                $enum::from($name);
                self::account($account);
            }
        }
        foreach (["charge", "payment", "refund"] as $key) {
            if ($data[$key]["id"] === $data["receivable"]["id"]) {
                throw new InvalidArgumentException(
                    "Choose a counterpart distinct from accounts receivable.",
                );
            }
        }
        $mappedOverrides = [
            ...array_values($data["charge_sources"]),
            ...array_values($data["payment_methods"]),
        ];
        foreach ($mappedOverrides as $account) {
            if ($account["id"] === $data["receivable"]["id"]) {
                throw new InvalidArgumentException(
                    "Accounts receivable cannot be its own counterpart.",
                );
            }
        }
    }

    public static function account(array $data): void
    {
        self::keys($data, ["id", "code", "title"]);
        new EntityId($data["id"]);
        foreach (["code" => 80, "title" => 191] as $field => $maximum) {
            if (
                !is_string($data[$field]) ||
                trim($data[$field]) === "" ||
                !mb_check_encoding($data[$field], "UTF-8") ||
                mb_strlen($data[$field]) > $maximum ||
                preg_match('/[\x00-\x1F\x7F]/', $data[$field])
            ) {
                throw new InvalidArgumentException(
                    "Enter an account code and title.",
                );
            }
        }
    }

    public static function period(array $data): void
    {
        self::keys($data, [
            "book_id",
            "currency",
            "label",
            "starts_on",
            "ends_on",
            "closed",
            "movement_count",
            "digest",
            "ar_net",
        ]);
        new EntityId($data["book_id"]);
        new Money(0, $data["currency"]);
        if (
            !is_string($data["label"]) ||
            trim($data["label"]) === "" ||
            mb_strlen($data["label"]) > 80 ||
            !mb_check_encoding($data["label"], "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $data["label"])
        ) {
            throw new InvalidArgumentException(
                "Enter a period label of up to 80 characters.",
            );
        }
        foreach (["starts_on", "ends_on"] as $field) {
            $date = is_string($data[$field])
            ? DateTimeImmutable::createFromFormat("!Y-m-d", $data[$field])
            : false;
            if ($date === false || $date->format("Y-m-d") !== $data[$field]) {
                throw new InvalidArgumentException(
                    "Enter a valid accounting date.",
                );
            }
        }
        if (
            $data["starts_on"] > $data["ends_on"] ||
            !is_bool($data["closed"]) ||
            !is_int($data["movement_count"]) ||
            $data["movement_count"] < 0 ||
            $data["movement_count"] > 2000000000 ||
            !is_string($data["digest"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $data["digest"]) !== 1 ||
            !is_string($data["ar_net"]) ||
            (string) BigInteger::of($data["ar_net"]) !== $data["ar_net"]
        ) {
            throw new InvalidArgumentException("Invalid accounting period.");
        }
    }

    public static function hash(mixed $data): string
    {
        if (is_array($data)) {
            $data = self::ordered($data);
        }
        return hash(
            "sha256",
            json_encode(
                $data,
                JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    public static function accounting(array $data): void
    {
        self::keys($data, [
            "book_id",
            "period_id",
            "configuration_event_id",
            "source",
        ]);
        foreach (["book_id", "period_id", "configuration_event_id"] as $field) {
            new EntityId($data[$field]);
        }
        if ($data["source"] === null) {
            return;
        }
        if (($data['source']['kind'] ?? null) === 'service_fee') {
            self::keys($data['source'], ['kind', 'fee_id', 'event_id']);
            new EntityId($data['source']['fee_id']);
            new EntityId($data['source']['event_id']);
            return;
        }
        self::keys($data["source"], [
            "account_id",
            "event_id",
            "entry_operation",
            "entry_basis",
        ]);
        foreach (["account_id", "event_id"] as $field) {
            new EntityId($data["source"][$field]);
        }
        if ($data["source"]["entry_operation"] !== null) {
            new EntityId($data["source"]["entry_operation"]);
        }
        if ($data["source"]["entry_basis"] !== null) {
            $basis = $data["source"]["entry_basis"];
            self::keys($basis, ["type", "minor", "counterpart_id"]);
            \Domain\Finance\Enum\EntryType::from($basis["type"]);
            if (
                !is_int($basis["minor"]) ||
                $basis["minor"] < 1 ||
                $basis["minor"] > Money::MAX_MINOR
            ) {
                throw new InvalidArgumentException(
                    "Invalid ledger source amount.",
                );
            }
            new EntityId($basis["counterpart_id"]);
        }
        if (
            ($data["source"]["entry_operation"] === null) !==
            ($data["source"]["entry_basis"] === null)
        ) {
            throw new InvalidArgumentException("Invalid ledger source basis.");
        }
    }

    private static function ordered(array $data): array
    {
        if (!array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::ordered($value);
            }
        }
        return $data;
    }

    public static function movement(
        string $prior,
        int $number,
        string $event,
    ): string {
        return self::hash([$prior, $number, $event]);
    }
}
