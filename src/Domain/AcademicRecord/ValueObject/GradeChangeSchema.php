<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\AcademicRecord\Event\GradeChangeWasRequested;
use Domain\AcademicRecord\Event\GradeChangeWasResolved;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class GradeChangeSchema
{
    public const array REQUEST = [
        "decision_id",
        "previous_decision_id",
        "actor_id",
        "grade",
        "before_grade",
        "assignment_id",
        "reason",
        "effective_at",
        "policy",
        "credit_version",
    ];
    public const array RESOLUTION = [
        "decision_id",
        "previous_decision_id",
        "actor_id",
        "request_id",
        "outcome",
        "grade",
        "assignment_id",
    ];

    public static function policy(array $policy): void
    {
        if (
            count($policy) !== 2 ||
            !in_array($policy["mode"] ?? null, ["direct", "approval"], true) ||
            !is_int($policy["revision"] ?? null) ||
            $policy["revision"] < 0 ||
            $policy["revision"] > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function validate(string $class, array $payload): void
    {
        $fields = $class === GradeChangeWasRequested::class ? self::REQUEST : self::RESOLUTION;
        if (
            !in_array($class, [GradeChangeWasRequested::class, GradeChangeWasResolved::class], true) ||
            count($payload) !== count($fields) ||
            array_diff(array_keys($payload), $fields) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["decision_id", "previous_decision_id", "actor_id"] as $key) {
            if (!is_string($payload[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($payload[$key]);
        }
        if ($payload["decision_id"] === $payload["previous_decision_id"]) {
            throw new CorruptHistoryException();
        }
        if ($payload["assignment_id"] !== null) {
            if (!is_string($payload["assignment_id"])) {
                throw new CorruptHistoryException();
            }
            new EntityId($payload["assignment_id"]);
        }
        if ($class === GradeChangeWasRequested::class) {
            if (
                !is_array($payload["grade"]) ||
                !is_array($payload["before_grade"]) ||
                !is_array($payload["policy"]) ||
                !is_int($payload["credit_version"]) ||
                $payload["credit_version"] < 0 ||
                !is_string($payload["reason"]) ||
                trim($payload["reason"]) === "" ||
                mb_strlen($payload["reason"]) > 1000 ||
                !mb_check_encoding($payload["reason"], "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $payload["reason"]) ||
                !is_string($payload["effective_at"]) ||
                \DateTimeImmutable::createFromFormat(DATE_ATOM, $payload["effective_at"]) === false
            ) {
                throw new CorruptHistoryException();
            }
            $when = \DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['effective_at']);
            if ($when->format(DATE_ATOM) !== $payload['effective_at']) {
                throw new CorruptHistoryException();
            }
            new GradeSnapshot($payload["grade"]);
            new GradeSnapshot($payload["before_grade"]);
            self::policy($payload["policy"]);
            if ($payload["policy"]["mode"] !== "approval") {
                throw new CorruptHistoryException();
            }
        } else {
            if (
                !is_string($payload["request_id"]) ||
                !in_array($payload["outcome"], ["approved", "rejected", "cancelled"], true)
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($payload["request_id"]);
            if ($payload["outcome"] === "approved") {
                if (!is_array($payload["grade"])) {
                    throw new CorruptHistoryException();
                }
                new GradeSnapshot($payload["grade"]);
            } elseif ($payload["grade"] !== null || $payload["assignment_id"] !== null) {
                throw new CorruptHistoryException();
            }
        }
    }
}
