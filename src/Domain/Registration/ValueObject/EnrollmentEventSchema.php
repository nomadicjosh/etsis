<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use Domain\Registration\Event\EnrollmentWasEstablished;
use Domain\Registration\Event\EnrollmentWasClosed;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final class EnrollmentEventSchema
{
    /** @param array<string,mixed> $p */
    public static function validate(string $class, #[\SensitiveParameter] array $p): void
    {
        $keys = match ($class) {
            EnrollmentWasEstablished::class => [
                "decision_id",
                "provenance",
                "enrollment",
                "source",
                "eligibility",
                "seat",
            ],
            EnrollmentWasClosed::class => [
                "decision_id",
                "previous_decision_id",
                "status",
                "status_at",
                "policy",
                "seat",
            ],
            default => [],
        };
        self::keys($p, $keys);
        self::id($p["decision_id"] ?? null);
        if ($class === EnrollmentWasEstablished::class) {
            if (
                !in_array($p["provenance"], ["registered", "observed_existing"], true) ||
                !is_array($p["enrollment"]) ||
                !is_array($p["source"])
            ) {
                self::invalid();
            }
            new EnrollmentFacts($p["enrollment"]);
            self::source($p["source"]);
            if (!in_array($p["enrollment"]["status"], ["A", "N"], true)) {
                self::invalid();
            }
            if ($p["provenance"] === "observed_existing") {
                if (
                    $p["eligibility"] !== null ||
                    $p["seat"] !== null ||
                    $p["source"]["course_version_id"] !== null
                ) {
                    self::invalid();
                }
            } else {
                if (
                    $p["decision_id"] !== $p["enrollment"]["id"] ||
                    $p["enrollment"]["version"] !== 0 ||
                    !$p["source"]["active"] ||
                    !is_array($p["eligibility"]) ||
                    !is_array($p["seat"])
                ) {
                    self::invalid();
                }
                self::eligibility($p["eligibility"]);
                self::seat($p["seat"], 1);
                if (
                    $p["seat"]["capacity"] !== $p["source"]["capacity"] ||
                    $p["seat"]["used_before"] >= $p["seat"]["capacity"] ||
                    $p["eligibility"]["by_staff"] !== ($p["enrollment"]["status"] === "A") ||
                    (!$p["eligibility"]["by_staff"] &&
                        (!$p["source"]["web_registration"] || !$p["eligibility"]["self_service_open"]))
                ) {
                    self::invalid();
                }
            }
        } else {
            self::id($p["previous_decision_id"]);
            if (
                $p["previous_decision_id"] === $p["decision_id"] ||
                !in_array($p["status"], ["D", "W"], true) ||
                !is_array($p["policy"]) ||
                !is_array($p["seat"])
            ) {
                self::invalid();
            }
            EnrollmentFacts::timestamp($p["status_at"]);
            self::keys($p["policy"], ["grace_ends_on", "version", "remove_current"]);
            $policy = $p["policy"];
            if (
                !is_string($policy["grace_ends_on"]) ||
                !($date = DateTimeImmutable::createFromFormat("!Y-m-d", $policy["grace_ends_on"])) ||
                $date->format("Y-m-d") !== $policy["grace_ends_on"] ||
                !is_int($policy["version"]) ||
                $policy["version"] < -1 ||
                !is_bool($policy["remove_current"]) ||
                $policy["remove_current"] !== ($p["status"] === "D")
            ) {
                self::invalid();
            }
            self::seat($p["seat"], -1);
        }
    }
    /** @param array<string,mixed> $s */
    private static function source(array $s): void
    {
        self::keys($s, [
            "course_id",
            "course_version_id",
            "section_code",
            "term_code",
            "capacity",
            "active",
            "web_registration",
            "fee_minor",
            "currency",
            "delivery_mode",
            "faculty_user_id",
        ]);
        self::id($s["course_id"]);
        foreach (["course_version_id", "faculty_user_id"] as $key) {
            if ($s[$key] !== null) {
                self::id($s[$key]);
            }
        }
        foreach (["section_code" => 80, "term_code" => 96] as $key => $max) {
            if (
                !is_string($s[$key]) ||
                trim($s[$key]) === "" ||
                mb_strlen($s[$key]) > $max ||
                !mb_check_encoding($s[$key], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $s[$key])
            ) {
                self::invalid();
            }
        }
        if (
            !is_int($s["capacity"]) ||
            $s["capacity"] < 1 ||
            $s["capacity"] > 100000 ||
            !is_bool($s["active"]) ||
            !is_bool($s["web_registration"]) ||
            !is_int($s["fee_minor"]) ||
            $s["fee_minor"] < 0 ||
            !is_string($s["currency"]) ||
            preg_match('/^[A-Z]{3}$/D', $s["currency"]) !== 1 ||
            !in_array($s["delivery_mode"], ["unspecified", "in_person", "online", "hybrid"], true)
        ) {
            self::invalid();
        }
    }
    /** @param array<string,mixed> $e */
    private static function eligibility(array $e): void
    {
        self::keys($e, [
            "student_active",
            "by_staff",
            "self_service_open",
            "courses_before",
            "course_limit",
            "prerequisites_met",
            "required_course_ids",
            "opens_at",
            "closes_at",
        ]);
        if (
            $e["student_active"] !== true ||
            $e["prerequisites_met"] !== true ||
            !is_bool($e["by_staff"]) ||
            !is_bool($e["self_service_open"]) ||
            !is_int($e["courses_before"]) ||
            $e["courses_before"] < 0 ||
            !is_int($e["course_limit"]) ||
            $e["course_limit"] < 1 ||
            $e["course_limit"] > 1000 ||
            $e["courses_before"] >= $e["course_limit"] ||
            !is_array($e["required_course_ids"]) ||
            !array_is_list($e["required_course_ids"]) ||
            count($e["required_course_ids"]) > 100 ||
            count($e["required_course_ids"]) !== count(array_unique($e["required_course_ids"], SORT_REGULAR))
        ) {
            self::invalid();
        }
        foreach ($e["required_course_ids"] as $id) {
            self::id($id);
        }
        EnrollmentFacts::timestamp($e["opens_at"]);
        EnrollmentFacts::timestamp($e["closes_at"]);
        if ($e["closes_at"] < $e["opens_at"]) {
            self::invalid();
        }
    }
    /** @param array<string,mixed> $s */
    private static function seat(array $s, int $delta): void
    {
        self::keys($s, ["sequence", "previous_decision_id", "used_before", "used_after", "capacity"]);
        foreach (["sequence", "used_before", "used_after", "capacity"] as $key) {
            if (!is_int($s[$key]) || $s[$key] < 0 || $s[$key] > 2147483646) {
                self::invalid();
            }
        }
        if (
            $s["sequence"] < 1 ||
            $s["capacity"] < 1 ||
            $s["capacity"] > 100000 ||
            $s["used_after"] !== $s["used_before"] + $delta ||
            ($s["sequence"] === 1) !== ($s["previous_decision_id"] === null)
        ) {
            self::invalid();
        }
        if ($s["previous_decision_id"] !== null) {
            self::id($s["previous_decision_id"]);
        }
    }
    /** @param array<string,mixed> $a
     * @param list<string> $keys */
    private static function keys(array $a, array $keys): void
    {
        if (count($a) !== count($keys) || array_diff(array_keys($a), $keys) !== []) {
            self::invalid();
        }
    }
    private static function id(mixed $id): void
    {
        if (!is_string($id)) {
            self::invalid();
        }
        new EntityId($id);
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid retained registration decision.");
    }
}
