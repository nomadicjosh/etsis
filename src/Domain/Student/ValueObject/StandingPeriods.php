<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Recorded institutional determinations, not an automatic eligibility or GPA policy. */
final readonly class StandingPeriods
{
    public const array FIELDS = [
        "id",
        "definition_id",
        "code",
        "title",
        "starts_on",
        "ends_on",
        "policy_reference",
    ];
    public array $rows;

    public function __construct(#[\SensitiveParameter] array $rows)
    {
        if (!array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException("Use up to fifty dated standing periods.");
        }
        $normalized = $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 7 || array_diff(array_keys($row), self::FIELDS) !== []) {
                throw new InvalidArgumentException("Provide the complete standing period.");
            }
            foreach (["id", "definition_id"] as $key) {
                if (!is_string($row[$key])) {
                    throw new InvalidArgumentException("Choose a valid standing and period identity.");
                }
                new EntityId($row[$key]);
            }
            self::label($row["code"], $row["title"]);
            self::date($row["starts_on"]);
            if ($row["ends_on"] !== null) {
                self::date($row["ends_on"]);
                if ($row["ends_on"] < $row["starts_on"]) {
                    throw new InvalidArgumentException("End a standing period on or after its start date.");
                }
            }
            if ($row["policy_reference"] !== null) {
                if (
                    !is_string($row["policy_reference"]) ||
                    trim($row["policy_reference"]) === "" ||
                    !mb_check_encoding($row["policy_reference"], "UTF-8") ||
                    mb_strlen($row["policy_reference"]) > 200 ||
                    preg_match('/[\x00-\x1F\x7F]/', $row["policy_reference"])
                ) {
                    throw new InvalidArgumentException("Use a policy reference of up to 200 characters.");
                }
            }
            if (isset($ids[$row["id"]])) {
                throw new InvalidArgumentException("Use distinct standing period identities.");
            }
            $ids[$row["id"]] = true;
            $normalized[] = array_replace(array_fill_keys(self::FIELDS, null), $row);
        }
        usort($normalized, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        foreach ($normalized as $i => $a) {
            foreach (array_slice($normalized, $i + 1) as $b) {
                if (
                    $a["starts_on"] <= ($b["ends_on"] ?? "9999-12-31") &&
                    $b["starts_on"] <= ($a["ends_on"] ?? "9999-12-31")
                ) {
                    throw new InvalidArgumentException(
                        "Standing periods cannot overlap. End the earlier period first.",
                    );
                }
            }
        }
        $this->rows = $normalized;
    }

    public static function label(mixed $code, mixed $title): void
    {
        if (
            !is_string($code) ||
            preg_match('~^[A-Z0-9][A-Z0-9_.\/-]{0,31}$~D', $code) !== 1 ||
            !is_string($title) ||
            trim($title) === "" ||
            $title !== trim($title) ||
            !mb_check_encoding($title, "UTF-8") ||
            mb_strlen($title) > 120 ||
            preg_match('/[\x00-\x1F\x7F]/', $title)
        ) {
            throw new InvalidArgumentException(
                "Use a standing code of up to 32 characters and a name of up to 120.",
            );
        }
    }

    public static function date(mixed $date): void
    {
        if (
            !is_string($date) ||
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 ||
            !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new InvalidArgumentException("Use valid calendar dates for standing periods.");
        }
    }

    public function fingerprint(): string
    {
        return hash("sha256", json_encode($this->rows, JSON_THROW_ON_ERROR));
    }
}
