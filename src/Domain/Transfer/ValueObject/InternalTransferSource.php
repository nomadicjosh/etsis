<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\AcademicRecord\Service\WorkPursuitLedger;

final class InternalTransferSource
{
    public const array FIELDS = [
        "source_credit_id",
        "source_pursuit_id",
        "source_credential_id",
        "receiving_assignment_id",
        "graduation_version",
        "academic_version",
        "registration_version",
        "pursuit_version",
        "source_hash",
        "intent",
        "course_id",
        "course_code",
        "course_title",
        "completed_on",
        "credits_milli",
        "grade",
        "source_program_code",
        "source_program_title",
        "credential_title",
        "credential_awarded_on",
        "person",
    ];
    public static function validate(array $s): void
    {
        if (
            count($s) !== count(self::FIELDS) ||
            array_diff(array_keys($s), self::FIELDS) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (
            [
                "source_credit_id",
                "source_pursuit_id",
                "source_credential_id",
                "receiving_assignment_id",
                "course_id",
            ] as $key
        ) {
            new EntityId($s[$key]);
        }
        if ($s["source_pursuit_id"] === $s["receiving_assignment_id"]) {
            throw new CorruptHistoryException();
        }
        foreach (
            [
                "graduation_version",
                "academic_version",
                "registration_version",
                "pursuit_version",
            ] as $key
        ) {
            if (!is_int($s[$key]) || $s[$key] < 1) {
                throw new CorruptHistoryException();
            }
        }
        foreach (["source_hash", "intent"] as $key) {
            if (
                !is_string($s[$key]) ||
                preg_match('/^[a-f0-9]{64}$/D', $s[$key]) !== 1
            ) {
                throw new CorruptHistoryException();
            }
        }
        foreach (
            [
                "course_code" => 96,
                "course_title" => 191,
                "source_program_code" => 191,
                "source_program_title" => 191,
                "credential_title" => 300,
            ] as $key => $max
        ) {
            if (
                !is_string($s[$key]) ||
                trim($s[$key]) === "" ||
                mb_strlen($s[$key]) > $max ||
                !mb_check_encoding($s[$key], "UTF-8") ||
                preg_match('/[\x00-\x1f\x7f]/', $s[$key])
            ) {
                throw new CorruptHistoryException();
            }
        }
        $date = is_string($s["completed_on"])
        ? \DateTimeImmutable::createFromFormat("!Y-m-d", $s["completed_on"])
        : false;
        if (
            !$date ||
            $date->format("Y-m-d") !== $s["completed_on"] ||
            !is_int($s["credits_milli"]) ||
            $s["credits_milli"] < 1 ||
            $s["credits_milli"] > 999999
        ) {
            throw new CorruptHistoryException();
        }
        $awarded = is_string($s["credential_awarded_on"])
        ? \DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $s["credential_awarded_on"],
        )
        : false;
        if (
            !$awarded ||
            $awarded->format("Y-m-d") !== $s["credential_awarded_on"] ||
            $s["completed_on"] > $s["credential_awarded_on"]
        ) {
            throw new CorruptHistoryException();
        }
        $ref = $s["person"];
        if (
            !is_array($ref) ||
            count($ref) !== 4 ||
            array_diff(array_keys($ref), [
                "person_id",
                "core_version",
                "history_recorded",
                "earlier_history_unknown",
            ]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($ref["person_id"]);
        if (
            !is_int($ref["core_version"]) ||
            $ref["core_version"] < 0 ||
            !is_bool($ref["history_recorded"]) ||
            !is_bool($ref["earlier_history_unknown"]) ||
            $ref["history_recorded"] !== $ref["core_version"] > 0
        ) {
            throw new CorruptHistoryException();
        }
        $grade = new GradeSnapshot($s["grade"]);
        if (!$grade->facts["passes"]) {
            throw new CorruptHistoryException();
        }
    }
    public static function hash(mixed $value): string
    {
        return WorkPursuitLedger::fingerprint($value);
    }
}
