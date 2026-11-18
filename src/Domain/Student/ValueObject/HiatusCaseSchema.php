<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class HiatusCaseSchema
{
    public const array FIELDS = [
        "operation_id",
        "previous_operation_id",
        "action",
        "facts",
        "request",
        "request_hash",
        "result",
        "return_operation_id",
        "previous_ends_on",
        "confirmed_at",
    ];

    /** @param array<string,mixed> $p */
    public static function validate(#[\SensitiveParameter] array $p): void
    {
        HiatusFacts::keys($p, self::FIELDS);
        $fields1 = ["operation_id", "previous_operation_id", "return_operation_id"];
        foreach ($fields1 as $key) {
            if ($key !== "operation_id" && $p[$key] === null) {
                continue;
            }
            if (!is_string($p[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        $confirmed = is_string($p["confirmed_at"])
        ? DateTimeImmutable::createFromFormat(DATE_ATOM, $p["confirmed_at"])
        : false;
        if (
            !is_array($p["facts"]) ||
            $confirmed === false ||
            $confirmed->format(DATE_ATOM) !== $p["confirmed_at"] ||
            !str_ends_with($p["confirmed_at"], "+00:00")
        ) {
            throw new CorruptHistoryException();
        }
        new HiatusFacts($p["facts"]);
        if ($p["previous_ends_on"] !== null) {
            HiatusFacts::date($p["previous_ends_on"]);
        }
        if ($p["action"] === "observed") {
            if (
                $p["request"] !== null ||
                $p["request_hash"] !== null ||
                $p["result"] !== null ||
                $p["previous_operation_id"] !== null ||
                $p["previous_ends_on"] !== null
            ) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (
            !is_array($p["request"]) ||
            !is_string($p["request_hash"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["request_hash"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        $r = $p["request"];
        switch ($p["action"]) {
            case "created":
                HiatusFacts::keys(
                    $r,
                    isset($r["impact"])
                        ? ["code", "starts_on", "ends_on", "impact"]
                        : ["code", "starts_on", "ends_on"],
                );
                if (
                    $p["facts"]["version"] !== 0 ||
                    $p["facts"]["voided"] ||
                    $p["return_operation_id"] !== null ||
                    $p["previous_operation_id"] !== null ||
                    $p["previous_ends_on"] !== null ||
                    $r["code"] !== $p["facts"]["code"] ||
                    $r["starts_on"] !== $p["facts"]["starts_on"] ||
                    $r["ends_on"] !== $p["facts"]["ends_on"]
                ) {
                    throw new CorruptHistoryException();
                }
                self::impact(
                    $r["impact"] ?? null,
                    $p["result"],
                    $p["facts"]["starts_on"],
                );
                break;
            case "end_changed":
                HiatusFacts::keys($r, ["ends_on", "revision"]);
                self::revision($r["revision"]);
                if (
                    $r["ends_on"] !== $p["facts"]["ends_on"] ||
                    $p["facts"]["voided"] ||
                    $p["result"] !== null
                ) {
                    throw new CorruptHistoryException();
                }
                break;
            case "voided":
                HiatusFacts::keys($r, ["revision"]);
                self::revision($r["revision"]);
                if (!$p["facts"]["voided"] || $p["result"] !== null) {
                    throw new CorruptHistoryException();
                }
                break;
            case "returned":
                HiatusFacts::keys($r, ["on", "revision", "reactivate"]);
                self::revision($r["revision"]);
                HiatusFacts::date($r["on"]);
                if (
                    !is_bool($r["reactivate"]) ||
                    $p["facts"]["voided"] ||
                    $r["on"] <= $p["facts"]["starts_on"] ||
                    $p["return_operation_id"] !== $p["operation_id"] ||
                    !is_array($p["result"])
                ) {
                    throw new CorruptHistoryException();
                }
                $result = $p["result"];
                HiatusFacts::keys($result, [
                    "return_on",
                    "hiatus_ends_on",
                    "student_active",
                    "activity_changed",
                    "staff_registration_blocked",
                    "self_registration_blocked",
                    "enrollments_restored",
                    "financial_changes",
                ]);
                $fields2 = [
                        "student_active",
                        "activity_changed",
                        "staff_registration_blocked",
                        "self_registration_blocked",
                    ];
                foreach ($fields2 as $key) {
                    if (!is_bool($result[$key])) {
                        throw new CorruptHistoryException();
                    }
                }
                if (
                    $result["return_on"] !== $r["on"] ||
                    $result["hiatus_ends_on"] !== $p["facts"]["ends_on"] ||
                    $result["enrollments_restored"] !== false ||
                    $result["financial_changes"] !== false ||
                    ($r["reactivate"] && !$result["student_active"]) ||
                    ($result["activity_changed"] &&
                        (!$r["reactivate"] || !$result["student_active"])) ||
                    $p["facts"]["ends_on"] === null ||
                    $p["facts"]["ends_on"] >= $r["on"]
                ) {
                    throw new CorruptHistoryException();
                }
                break;
            default:
                throw new CorruptHistoryException();
        }
    }

    private static function revision(mixed $value): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[a-f0-9]{64}$/D', $value) !== 1
        ) {
            throw new CorruptHistoryException();
        }
    }

    private static function impact(
        mixed $request,
        mixed $result,
        string $date,
    ): void {
        if ($request === null) {
            if ($result !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (!is_array($request) || !is_array($result)) {
            throw new CorruptHistoryException();
        }
        HiatusFacts::keys($request, ["section_ids", "deactivate", "revision"]);
        self::revision($request["revision"]);
        if (
            !is_bool($request["deactivate"]) ||
            !is_array($request["section_ids"]) ||
            !array_is_list($request["section_ids"]) ||
            count($request["section_ids"]) > 100 ||
            count(array_unique($request["section_ids"], SORT_REGULAR)) !==
                count($request["section_ids"])
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($request["section_ids"] as $id) {
            if (!is_string($id)) {
                throw new CorruptHistoryException();
            }
            new EntityId($id);
        }
        HiatusFacts::keys($result, [
            "course_changes",
            "student_activity_changed",
            "student_active",
            "automatic_refund",
        ]);
        if (
            !is_bool($result["student_activity_changed"]) ||
            !is_bool($result["student_active"]) ||
            $result["automatic_refund"] !== false ||
            !is_array($result["course_changes"]) ||
            !array_is_list($result["course_changes"]) ||
            count($result["course_changes"]) > 100 ||
            ($request["deactivate"] && $result["student_active"]) ||
            ($result["student_activity_changed"] && !$request["deactivate"])
        ) {
            throw new CorruptHistoryException();
        }
        $sections = [];
        foreach ($result["course_changes"] as $change) {
            if (!is_array($change)) {
                throw new CorruptHistoryException();
            }
            HiatusFacts::keys($change, [
                "enrollment_id",
                "section_id",
                "course_code",
                "term_code",
                "previous_status",
                "status",
                "effective_date",
                "grade_code",
                "academic_records_removed",
                "course_charge",
                "drop_operation_id",
            ]);
            $fields3 = ["enrollment_id", "section_id", "drop_operation_id"];
            foreach ($fields3 as $field) {
                if (!is_string($change[$field])) {
                    throw new CorruptHistoryException();
                }
                new EntityId($change[$field]);
            }
            $fields4 = ["course_code", "term_code"];
            foreach ($fields4 as $field) {
                if (
                    !is_string($change[$field]) ||
                    trim($change[$field]) === "" ||
                    strlen($change[$field]) > 80 ||
                    preg_match('/[\x00-\x1F\x7F]/', $change[$field])
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                !in_array($change["previous_status"], ["A", "N"], true) ||
                !in_array($change["status"], ["D", "W"], true) ||
                !is_bool($change["academic_records_removed"]) ||
                $change["academic_records_removed"] !==
                    ($change["status"] === "D") ||
                $change["effective_date"] !== $date ||
                !in_array(
                    $change["course_charge"],
                    ["none", "reversed", "kept"],
                    true,
                ) ||
                ($change["academic_records_removed"]
                    ? $change["grade_code"] !== null ||
                        $change["course_charge"] === "kept"
                    : !is_string($change["grade_code"]) ||
                        preg_match(
                            '/^[A-Z0-9+.-]{1,12}$/D',
                            $change["grade_code"],
                        ) !== 1 ||
                        $change["course_charge"] !== "kept")
            ) {
                throw new CorruptHistoryException();
            }
            $sections[] = $change["section_id"];
        }
        if ($sections !== $request["section_ids"]) {
            throw new CorruptHistoryException();
        }
    }
}
