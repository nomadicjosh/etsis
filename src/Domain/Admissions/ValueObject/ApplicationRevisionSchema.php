<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class ApplicationRevisionSchema
{
    public const array FIELDS = [
        "decision_id",
        "previous_decision_id",
        "application_version",
        "revision",
    ];
    public const array REFERENCE = [
        "id",
        "original_submission_id",
        "form_id",
        "form_hash",
        "fingerprint",
        "owner_user_id",
        "submitted_by",
        "submitted_at",
        "channel",
        "policy",
    ];

    public static function validate(#[\SensitiveParameter] array $p): void
    {
        if (
            count($p) !== 4 ||
            array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_int($p["application_version"]) ||
            $p["application_version"] < 1
        ) {
            throw new CorruptHistoryException();
        }
        self::id($p["decision_id"]);
        self::id($p["previous_decision_id"]);
        if ($p["decision_id"] === $p["previous_decision_id"]) {
            throw new CorruptHistoryException();
        }
        $r = $p["revision"];
        if (
            !is_array($r) ||
            count($r) !== count(self::REFERENCE) ||
            array_diff(array_keys($r), self::REFERENCE) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["id", "original_submission_id", "form_id", "owner_user_id", "submitted_by"] as $key) {
            self::id($r[$key]);
        }
        foreach (["fingerprint", "form_hash"] as $key) {
            if (
                !is_string($r[$key]) ||
                preg_match('/^[a-f0-9]{64}$/D', $r[$key]) !== 1
            ) {
                throw new CorruptHistoryException();
            }
        }
        if (
            !in_array($r["channel"], ["owner", "admissions_staff"], true) ||
            ($r["channel"] === "owner" &&
                $r["submitted_by"] !== $r["owner_user_id"]) ||
            !is_string($r["submitted_at"]) ||
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',
                $r["submitted_at"],
            ) !== 1 ||
            new \DateTimeImmutable($r["submitted_at"])->format(DATE_ATOM) !==
                $r["submitted_at"] ||
            !is_array($r["policy"]) ||
            count($r["policy"]) !== 2 ||
            !in_array(
                $r["policy"]["mode"] ?? null,
                ["request", "before_decision"],
                true,
            ) ||
            !is_int($r["policy"]["revision"] ?? null) ||
            $r["policy"]["revision"] < 0
        ) {
            throw new CorruptHistoryException();
        }
    }
    private static function id(mixed $v): void
    {
        if (!is_string($v)) {
            throw new CorruptHistoryException();
        }
        new EntityId($v);
    }
    public static function fingerprint(
        #[\SensitiveParameter] array $artifact,
    ): string {
        // Canonical object ordering makes re-encryption and JSON key order immaterial.
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return hash(
            "sha256",
            json_encode(
                $sort($artifact),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
