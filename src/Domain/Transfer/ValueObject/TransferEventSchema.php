<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use DateTimeImmutable;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Event\TransferCreditWasEstablished;
use Domain\Transfer\Event\TransferCreditWasChanged;
use Domain\Transfer\Event\TransferAcademicPeriodWasRecorded;
use Domain\Transfer\Event\TransferCourseWasAccepted;
use Domain\Transfer\Event\TransferCourseWasReplaced;
use Domain\Transfer\Event\TransferAcademicDatesWereRecorded;
use Domain\Transfer\Event\TransferPostingTermWasChanged;
use InvalidArgumentException;

final class TransferEventSchema
{
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $keys = match ($class) {
            TransferCreditWasEstablished::class => ["decision_id", "provenance", "source", "credit", "grade"],
            TransferAcademicPeriodWasRecorded::class => [
                "decision_id",
                "previous_decision_id",
                "previous_academic_on",
                "academic_on",
            ],
            TransferAcademicDatesWereRecorded::class => [
                "decision_id",
                "previous_decision_id",
                "previous_academic_on",
                "academic_on",
                "previous_completed_on",
                "completed_on",
            ],
            TransferPostingTermWasChanged::class => [
                "decision_id",
                "previous_decision_id",
                "previous_academic_on",
                "academic_on",
                "previous_completed_on",
                "completed_on",
                "previous_term_id",
                "term_id",
                "previous_term_code",
                "term_code",
            ],
            TransferCourseWasReplaced::class => [
                "decision_id",
                "previous_decision_id",
                "previous_course_version_id",
                "course_version_id",
                "previous_course_id",
                "course_id",
                "previous_course_code",
                "previous_course_title",
                "course_code",
                "course_title",
            ],
            TransferCourseWasAccepted::class => [
                "decision_id",
                "previous_decision_id",
                "previous_course_version_id",
                "course_version_id",
                "course_id",
                "course_code",
                "course_title",
            ],
            TransferCreditWasChanged::class => [
                "decision_id",
                "previous_decision_id",
                "action",
                "credits_milli",
                "grade",
            ],
            default => [],
        };
        if (
            count($p) !== count($keys) ||
            array_diff(array_keys($p), $keys) !== [] ||
            !is_string($p["decision_id"] ?? null)
        ) {
            self::invalid();
        }
        new EntityId($p["decision_id"]);
        if ($class === TransferCreditWasEstablished::class) {
            if (
                !in_array($p["provenance"], ["award", "observed_existing", "internal_award"], true) ||
                !is_array($p["credit"]) ||
                !is_array($p["source"])
            ) {
                self::invalid();
            }
            new TransferCreditFacts($p["credit"]);
            self::source($p["source"], $p["provenance"] === "internal_award");
            foreach (["student_id", "course_id", "term_id"] as $key) {
                if ($p["credit"][$key] !== $p["source"][$key]) {
                    self::invalid();
                }
            }
            if ($p["source"]["award_id"] !== $p["credit"]["source_id"]) {
                self::invalid();
            }
            if ($p["grade"] !== null) {
                if (!is_array($p["grade"])) {
                    self::invalid();
                }
                new GradeSnapshot($p["grade"]);
                if ($p["grade"]["id"] !== $p["credit"]["grade_id"]) {
                    self::invalid();
                }
            } elseif ($p["credit"]["grade_id"] !== null) {
                self::invalid();
            }
            if ($p['provenance'] === 'internal_award') {
                if (
                    $p['grade'] === null || !$p['grade']['passes'] || $p['credit']['status'] !== 'A' ||
                    $p['credit']['version'] !== 0 || $p['credit']['attempted_milli'] < 1 ||
                    $p['credit']['attempted_milli'] !== $p['credit']['completed_milli'] ||
                    $p['credit']['completed_milli'] > $p['source']['internal']['credits_milli'] ||
                    $p['source']['completed_on'] !== $p['source']['internal']['completed_on'] ||
                    $p['credit']['grade_points_milli'] !== ($p['grade']['counts_gpa'] ?
                        intdiv($p['credit']['completed_milli'] * $p['grade']['points_milli'], 1000) : 0)
                ) {
                    self::invalid();
                }
            }
            if ($p["provenance"] === "award") {
                if (
                    $p["source"]["equivalency"] === null ||
                    $p["source"]["grade_rank"] === null ||
                    $p["grade"] === null ||
                    !$p["grade"]["passes"] ||
                    $p["credit"]["status"] !== "A" ||
                    $p["credit"]["version"] !== 0 ||
                    $p["credit"]["attempted_milli"] !== $p["source"]["equivalency"]["credits_milli"] ||
                    $p["credit"]["completed_milli"] !== $p["credit"]["attempted_milli"]
                ) {
                    self::invalid();
                }
                $r = $p["source"]["equivalency"];
                if ($p["grade"]["code"] !== $r["awarded_grade"]) {
                    self::invalid();
                }
                if (
                    $p["source"]["grade_rank"] < $r["minimum_grade_rank"] ||
                    $p["source"]["completed_on"] < $r["starts_on"] ||
                    ($r["ends_on"] !== null && $p["source"]["completed_on"] > $r["ends_on"])
                ) {
                    self::invalid();
                }
                $quality = $p["grade"]["counts_gpa"]
                ? intdiv($p["credit"]["attempted_milli"] * $p["grade"]["points_milli"], 1000)
                : 0;
                if ($quality !== $p["credit"]["grade_points_milli"]) {
                    self::invalid();
                }
            } elseif ($p["source"]["equivalency"] !== null || $p["source"]["grade_rank"] !== null) {
                self::invalid();
            }
        } else {
            if (!is_string($p["previous_decision_id"]) || $p["previous_decision_id"] === $p["decision_id"]) {
                self::invalid();
            }
            new EntityId($p["previous_decision_id"]);
            if (
                in_array($class, [TransferCourseWasAccepted::class, TransferCourseWasReplaced::class], true)
            ) {
                if ($class === TransferCourseWasReplaced::class) {
                    if (!is_string($p["previous_course_id"])) {
                        self::invalid();
                    }
                    new EntityId($p["previous_course_id"]);
                    if ($p["previous_course_id"] === $p["course_id"]) {
                        self::invalid();
                    }
                }
                foreach (["course_version_id", "course_id"] as $key) {
                    if (!is_string($p[$key])) {
                        self::invalid();
                    }
                    new EntityId($p[$key]);
                }
                if ($p["previous_course_version_id"] !== null) {
                    if (!is_string($p["previous_course_version_id"])) {
                        self::invalid();
                    }
                    new EntityId($p["previous_course_version_id"]);
                }
                if ($p["previous_course_version_id"] === $p["course_version_id"]) {
                    self::invalid();
                }
                $labels = ["course_code" => 96, "course_title" => 191];
                if ($class === TransferCourseWasReplaced::class) {
                    $labels += ["previous_course_code" => 96, "previous_course_title" => 191];
                }
                foreach ($labels as $key => $max) {
                    if (
                        !is_string($p[$key]) ||
                        trim($p[$key]) === "" ||
                        mb_strlen($p[$key]) > $max ||
                        !mb_check_encoding($p[$key], "UTF-8") ||
                        preg_match('/[\x00-\x1F\x7F]/', $p[$key])
                    ) {
                        self::invalid();
                    }
                }
                return;
            }
            if (
                in_array(
                    $class,
                    [TransferAcademicDatesWereRecorded::class, TransferPostingTermWasChanged::class],
                    true,
                )
            ) {
                $termChange = $class === TransferPostingTermWasChanged::class;
                if ($termChange) {
                    foreach (["previous_term_id", "term_id"] as $key) {
                        if (!is_string($p[$key])) {
                            self::invalid();
                        }
                        new EntityId($p[$key]);
                    }
                    if ($p["previous_term_id"] === $p["term_id"]) {
                        self::invalid();
                    }
                    foreach (["previous_term_code", "term_code"] as $key) {
                        if (
                            !is_string($p[$key]) ||
                            trim($p[$key]) === "" ||
                            mb_strlen($p[$key]) > 191 ||
                            !mb_check_encoding($p[$key], "UTF-8") ||
                            preg_match('/[\x00-\x1F\x7F]/', $p[$key])
                        ) {
                            self::invalid();
                        }
                    }
                }
                foreach (["previous_completed_on", "completed_on"] as $key) {
                    self::date($p[$key]);
                }
                foreach (["previous_academic_on", "academic_on"] as $key) {
                    if ($p[$key] !== null) {
                        self::date($p[$key]);
                    }
                }
                if (
                    ($p["academic_on"] !== null && $p["academic_on"] > $p["completed_on"]) ||
                    ($p["previous_academic_on"] !== null &&
                        $p["previous_academic_on"] > $p["previous_completed_on"]) ||
                    (!$termChange &&
                        $p["previous_academic_on"] === $p["academic_on"] &&
                        $p["previous_completed_on"] === $p["completed_on"])
                ) {
                    self::invalid();
                }
                return;
            }
            if ($class === TransferAcademicPeriodWasRecorded::class) {
                foreach (["previous_academic_on", "academic_on"] as $key) {
                    if ($p[$key] !== null) {
                        self::date($p[$key]);
                    }
                }
                if ($p["previous_academic_on"] === $p["academic_on"]) {
                    self::invalid();
                }
                return;
            }
            if (!in_array($p["action"], ["amend", "void", "reinstate"], true)) {
                self::invalid();
            }
            if ($p["action"] === "void") {
                if ($p["credits_milli"] !== null || $p["grade"] !== null) {
                    self::invalid();
                }
            } else {
                if (
                    !is_int($p["credits_milli"]) ||
                    $p["credits_milli"] < 1 ||
                    $p["credits_milli"] > 999999 ||
                    !is_array($p["grade"])
                ) {
                    self::invalid();
                }
                new GradeSnapshot($p["grade"]);
                if (!$p["grade"]["passes"]) {
                    self::invalid();
                }
            }
        }
    }
    /** @param array<string,mixed> $s */
    private static function source(array $s, bool $internal = false): void
    {
        $keys = [
            "award_id",
            "student_id",
            "equivalency_id",
            "course_id",
            "term_id",
            "completed_on",
            "institution_id",
            "institution_code",
            "institution_name",
            "external_course_id",
            "external_code",
            "external_title",
            "term_code",
            "equivalency",
            "grade_rank",
        ];
        if ($internal) {
            $keys[] = "internal";
            InternalTransferSource::validate($s["internal"] ?? []);
        }
        if (count($s) !== count($keys) || array_diff(array_keys($s), $keys) !== []) {
            self::invalid();
        }
        $identities = [
            "award_id",
            "student_id",
            "equivalency_id",
            "course_id",
            "term_id",
            "institution_id",
            "external_course_id",
        ];
        foreach ($identities as $key) {
            if ($internal && in_array($key, ['equivalency_id','institution_id','external_course_id'], true)) {
                if ($s[$key] !== null) {
                    self::invalid();

                } continue;
            }
            if (!is_string($s[$key])) {
                self::invalid();
            }
            new EntityId($s[$key]);
        }
        $labels = ["institution_code", "institution_name", "external_code", "external_title", "term_code"];
        foreach ($labels as $key) {
            if ($internal && $key !== 'term_code') {
                if ($s[$key] !== null) {
                    self::invalid();

                } continue;
            }
            if (
                !is_string($s[$key]) ||
                trim($s[$key]) === "" ||
                mb_strlen($s[$key]) > 191 ||
                !mb_check_encoding($s[$key], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $s[$key])
            ) {
                self::invalid();
            }
        }
        self::date($s["completed_on"]);
        if (
            $s["grade_rank"] !== null &&
            (!is_int($s["grade_rank"]) || $s["grade_rank"] < 0 || $s["grade_rank"] > 10000)
        ) {
            self::invalid();
        }
        if ($s["equivalency"] !== null) {
            $r = $s["equivalency"];
            $keys = [
                "starts_on",
                "ends_on",
                "minimum_grade_rank",
                "credits_milli",
                "grade_id",
                "awarded_grade",
            ];
            if (
                !is_array($r) ||
                count($r) !== 6 ||
                array_diff(array_keys($r), $keys) !== [] ||
                !is_int($r["minimum_grade_rank"]) ||
                $r["minimum_grade_rank"] < 0 ||
                $r["minimum_grade_rank"] > 10000 ||
                !is_int($r["credits_milli"]) ||
                $r["credits_milli"] < 1 ||
                $r["credits_milli"] > 999999 ||
                !is_string($r["grade_id"]) ||
                !is_string($r["awarded_grade"]) ||
                preg_match('/^[A-Z0-9+.-]{1,12}$/D', $r["awarded_grade"]) !== 1
            ) {
                self::invalid();
            }
            new EntityId($r["grade_id"]);
            self::date($r["starts_on"]);
            if ($r["ends_on"] !== null) {
                self::date($r["ends_on"]);
                if ($r["ends_on"] < $r["starts_on"]) {
                    self::invalid();
                }
            }
        }
    }
    private static function date(mixed $s): void
    {
        if (
            !is_string($s) ||
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s) !== 1 ||
            !($d = DateTimeImmutable::createFromFormat("!Y-m-d", $s)) ||
            $d->format("Y-m-d") !== $s
        ) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Invalid retained transfer event.");
    }
}
