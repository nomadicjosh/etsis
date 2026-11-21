<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\ValueObject\DerivedIdentity;
use InvalidArgumentException;

final class ReinstatementEventSchema
{
    public const array ENROLLMENT = [
        "decision_id",
        "operation_id",
        "previous_decision_id",
        "status",
        "status_at",
        "enrollment_version",
        "academic_credit_id",
        "academic_version",
        "revision",
        "intent",
        "eligibility",
        "seat",
    ];
    public const array ACADEMIC = [
        "decision_id",
        "operation_id",
        "previous_decision_id",
        "enrollment_id",
    ];
    public static function validate(array $p, bool $academic): void
    {
        $keys = $academic ? self::ACADEMIC : self::ENROLLMENT;
        if (count($p) !== count($keys) || array_diff(array_keys($p), $keys) !== []) {
            throw new InvalidArgumentException("Provide complete reinstatement facts.");
        }
        foreach (
            [
                "decision_id",
                "operation_id",
                "previous_decision_id",
                $academic ? "enrollment_id" : "academic_credit_id",
            ] as $key
        ) {
            new EntityId($p[$key]);
        }
        $operation = new EntityId($p["operation_id"]);
        if (
            DerivedIdentity::from(
                $operation,
                $academic ? "reinstatement.credit" : "reinstatement.enrollment",
            )->value !== $p["decision_id"]
        ) {
            throw new InvalidArgumentException("Use the accepted reinstatement identity.");
        }
        if ($academic) {
            return;
        }
        if (
            !in_array($p["status"], ["A", "N"], true) ||
            !is_int($p["enrollment_version"]) ||
            $p["enrollment_version"] < 2 ||
            $p["enrollment_version"] > 2147483646 ||
            !is_int($p["academic_version"]) ||
            $p["academic_version"] < 2 ||
            $p["academic_version"] > 2147483646 ||
            !is_string($p["revision"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["revision"]) !== 1 ||
            !is_string($p["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["intent"]) !== 1
        ) {
            throw new InvalidArgumentException(
                "Use exact reinstatement versions and review references.",
            );
        }
        EnrollmentFacts::timestamp($p["status_at"]);
        EnrollmentEventSchema::eligibility($p["eligibility"]);
        if ($p["eligibility"]["by_staff"] !== true) {
            throw new InvalidArgumentException("Retain a reviewed registrar decision.");
        }
        EnrollmentEventSchema::seat($p["seat"], 1);
    }
    public static function evidence(array $value, array $payload): void
    {
        $required = ["prerequisite_evidence", "schedule_evidence"];
        $allowed = [
            ...$required,
            "corequisite_evidence",
            "registration_window",
            "section_version_id",
        ];
        if (
            array_diff($required, array_keys($value)) !== [] ||
            array_diff(array_keys($value), $allowed) !== []
        ) {
            throw new InvalidArgumentException(
                "Retain complete bounded reinstatement eligibility evidence.",
            );
        }
        $prerequisites = PrerequisiteDecision::fromRetained($value["prerequisite_evidence"]);
        $schedule = new RegistrationScheduleDecision($value["schedule_evidence"]);
        if (
            $schedule->blocked() ||
            $prerequisites->required !== $payload["eligibility"]["required_course_ids"]
        ) {
            throw new InvalidArgumentException("Retain met reinstatement requirements.");
        }
        if (isset($value["corequisite_evidence"])) {
            CoRequisiteDecision::fromRetained($value["corequisite_evidence"]);
        }
        if (isset($value["registration_window"])) {
            RegistrationWindowDefinition::reference($value["registration_window"]);
        }
        if (isset($value["section_version_id"])) {
            new EntityId($value["section_version_id"]);
        }
    }
}
