<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\AcademicRecord\Event\FinalGradeWasDecided;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final class AcademicEventSchema
{
    /** @param array<string,mixed> $payload */
    public static function validate(string $class, array $payload): void
    {
        $keys = match ($class) {
            InstitutionalCreditWasEstablished::class => [
                "decision_id",
                "provenance",
                "credit",
                "section_id",
                "policies",
                "grade",
            ],
            FinalGradeWasDecided::class => ["decision_id", "previous_decision_id", "grade", "assignment_id"],
            InstitutionalCreditWasDropped::class => [
                "decision_id",
                "previous_decision_id",
                "outcome",
                "grade",
            ],
            default => [],
        };
        if (count($payload) !== count($keys) || array_diff(array_keys($payload), $keys) !== []) {
            self::invalid();
        }
        if (!is_string($payload["decision_id"] ?? null)) {
            self::invalid();
        }
        new EntityId($payload["decision_id"]);
        if ($class === InstitutionalCreditWasEstablished::class) {
            if (
                !in_array($payload["provenance"], ["registration", "observed_existing"], true) ||
                !is_array($payload["credit"]) ||
                !is_array($payload["policies"]) ||
                !is_string($payload["section_id"])
            ) {
                self::invalid();
            }
            $credit = new CreditSnapshot($payload["credit"]);
            new EntityId($payload["section_id"]);
            new PolicyReferences($payload["policies"]);
            if (
                $payload["provenance"] === "registration" &&
                ($credit->posted() ||
                    $payload["credit"]["version"] !== 0 ||
                    !in_array($payload["credit"]["status"], ["A", "N"], true))
            ) {
                self::invalid();
            }
            if ($payload["provenance"] === "observed_existing" && $payload["policies"] !== []) {
                self::invalid();
            }
            if ($payload["grade"] !== null) {
                if (
                    !is_array($payload["grade"]) ||
                    new GradeSnapshot($payload["grade"])->facts["id"] !== $payload["credit"]["grade_id"]
                ) {
                    self::invalid();
                }
            } elseif ($payload["credit"]["grade_id"] !== null) {
                self::invalid();
            }
        } else {
            if (
                !is_string($payload["previous_decision_id"]) ||
                $payload["decision_id"] === $payload["previous_decision_id"]
            ) {
                self::invalid();
            }
            new EntityId($payload["previous_decision_id"]);
            if ($class === FinalGradeWasDecided::class) {
                if (!is_array($payload["grade"])) {
                    self::invalid();
                }
                new GradeSnapshot($payload["grade"]);
                if ($payload["assignment_id"] !== null) {
                    if (!is_string($payload["assignment_id"])) {
                        self::invalid();
                    }
                    new EntityId($payload["assignment_id"]);
                }
            } else {
                $o = $payload["outcome"];
                $fields = [
                    "delete_records",
                    "grade_code",
                    "grade_id",
                    "points_milli",
                    "counts_gpa",
                    "policy_version",
                    "term_code",
                    "grace_ends_on",
                ];
                if (
                    !is_array($o) ||
                    count($o) !== 8 ||
                    array_diff(array_keys($o), $fields) !== [] ||
                    !is_bool($o["delete_records"]) ||
                    !is_bool($o["counts_gpa"]) ||
                    !is_int($o["points_milli"]) ||
                    $o["points_milli"] < 0 ||
                    $o["points_milli"] > 10000 ||
                    !is_int($o["policy_version"]) ||
                    $o["policy_version"] < -1 ||
                    !is_string($o["term_code"]) ||
                    mb_strlen($o["term_code"]) > 96 ||
                    !is_string($o["grace_ends_on"]) ||
                    preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $o["grace_ends_on"]) !== 1
                ) {
                    self::invalid();
                }
                if ($o["delete_records"]) {
                    if (
                        $o["grade_id"] !== null ||
                        $o["grade_code"] !== null ||
                        $o["points_milli"] !== 0 ||
                        $o["counts_gpa"] ||
                        $payload["grade"] !== null
                    ) {
                        self::invalid();
                    }
                } elseif ($payload["grade"] !== null) {
                    if (!is_array($payload["grade"])) {
                        self::invalid();
                    }
                    $g = new GradeSnapshot($payload["grade"])->facts;
                    if (
                        $g["id"] !== $o["grade_id"] ||
                        $g["code"] !== $o["grade_code"] ||
                        $g["points_milli"] !== $o["points_milli"] ||
                        $g["counts_gpa"] !== $o["counts_gpa"]
                    ) {
                        self::invalid();
                    }
                } elseif (
                    $o["grade_id"] !== null ||
                    $o["grade_code"] !== "W" ||
                    $o["points_milli"] !== 0 ||
                    $o["counts_gpa"]
                ) {
                    self::invalid();
                }
            }
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid institutional academic event.");
    }
}
