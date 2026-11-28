<?php

declare(strict_types=1);

namespace Domain\Tuition\ValueObject;

use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

final class TuitionSchema
{
    public static function definition(array $d): void
    {
        LedgerSchema::keys($d, ["name", "currency", "income", "brackets"]);
        self::text($d["name"], 191);
        new Money(0, $d["currency"]);
        LedgerSchema::account($d["income"]);
        if (
            !array_is_list($d["brackets"]) ||
            count($d["brackets"]) < 1 ||
            count($d["brackets"]) > 12
        ) {
            throw new \InvalidArgumentException(
                "Use one to twelve contiguous tuition brackets.",
            );
        }
        $next = 0;
        foreach ($d["brackets"] as $b) {
            LedgerSchema::keys($b, [
                "from_milli",
                "through_milli",
                "flat_minor",
                "rate_minor",
                "rate_from_milli",
            ]);
            foreach ($b as $v) {
                if (!is_int($v) || $v < 0) {
                    throw new \InvalidArgumentException(
                        "Use nonnegative integer pricing quantities.",
                    );
                }
            }
            if (
                $b["from_milli"] !== $next ||
                $b["through_milli"] < $next ||
                $b["through_milli"] > 999999 ||
                $b["rate_from_milli"] > $b["through_milli"]
            ) {
                throw new \InvalidArgumentException(
                    "Tuition brackets must cover zero through 999.999 credits once.",
                );
            }
            new Money($b["flat_minor"], $d["currency"]);
            new Money($b["rate_minor"], $d["currency"]);
            $next = $b["through_milli"] + 1;
        }
        if ($next !== 1000000) {
            throw new \InvalidArgumentException(
                "The final tuition bracket must cover 999.999 credits.",
            );
        }
    }

    public static function amount(array $d, int $credits): int
    {
        self::definition($d);
        if ($credits < 0 || $credits > 999999) {
            throw new \InvalidArgumentException(
                "The term credit quantity exceeds its supported limit.",
            );
        }
        if ($credits === 0) {
            return 0;
        }
        foreach ($d["brackets"] as $b) {
            if (
                $credits >= $b["from_milli"] &&
                $credits <= $b["through_milli"]
            ) {
                $minor =
                $b["flat_minor"] +
                intdiv(
                    max(0, $credits - $b["rate_from_milli"]) *
                            $b["rate_minor"] +
                            500,
                    1000,
                );
                new Money($minor, $d["currency"]);
                return $minor;
            }
        }
        throw new \LogicException("A covered tuition bracket is required.");
    }

    public static function text(mixed $v, int $max): void
    {
        if (
            !is_string($v) ||
            trim($v) !== $v ||
            $v === "" ||
            !mb_check_encoding($v, "UTF-8") ||
            mb_strlen($v) > $max ||
            preg_match('/[\x00-\x1F\x7F]/', $v)
        ) {
            throw new \InvalidArgumentException("Use bounded plain text.");
        }
    }

    public static function assignment(array $d): void
    {
        LedgerSchema::keys($d, ["scope", "scope_id", "schedule_id"]);
        if (!in_array($d["scope"], ["student", "program", "term"], true)) {
            throw new \InvalidArgumentException(
                "Choose a tuition assignment scope.",
            );
        }
        new EntityId($d["scope_id"]);
        if ($d["schedule_id"] !== null) {
            new EntityId($d["schedule_id"]);
        }
    }

    public static function course(array $d): void
    {
        LedgerSchema::keys($d, [
            "enrollment_id",
            "minor",
            "credits_milli",
            "section_label",
        ]);
        self::text($d["section_label"], 191);
        new EntityId($d["enrollment_id"]);
        if (
            !is_int($d["minor"]) ||
            $d["minor"] < 0 ||
            $d["minor"] > Money::MAX_MINOR ||
            !is_int($d["credits_milli"]) ||
            $d["credits_milli"] < 0 ||
            $d["credits_milli"] > 999999
        ) {
            throw new \InvalidArgumentException(
                "Retain the accepted registration fee and credit quantity.",
            );
        }
    }

    public static function line(array $d): void
    {
        LedgerSchema::keys($d, [
            "key",
            "name",
            "quoted_minor",
            "minor",
            "account",
            "enrollment_id",
            "price",
            "source",
        ]);
        self::text($d["key"], 100);
        self::text($d["name"], 191);
        LedgerSchema::account($d["account"]);
        \Domain\Finance\Enum\ChargeSource::from($d["source"]);
        if (
            !is_int($d["minor"]) ||
            !is_int($d["quoted_minor"]) ||
            $d["minor"] < 0 ||
            $d["quoted_minor"] < 0 ||
            $d["minor"] > Money::MAX_MINOR ||
            $d["quoted_minor"] > Money::MAX_MINOR
        ) {
            throw new \InvalidArgumentException("Use bounded invoice amounts.");
        }
        if ($d["enrollment_id"] !== null) {
            new EntityId($d["enrollment_id"]);
        }
        if ($d["price"] !== null) {
            \Domain\Finance\ValueObject\RegistrationPrice::fromRecorded(
                $d["price"],
            );
        }
    }
}
