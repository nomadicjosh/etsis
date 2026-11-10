<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen v1 contracts; no current-state row is represented as a historical submission event. */
final class AdmissionDecisionSchema
{
    public const array OBSERVED = ["decision_id", "application"];
    public const array DECIDED = [
        "decision_id",
        "previous_decision_id",
        "status",
        "application_version",
        "source",
    ];
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $keys = match ($class) {
            AdmissionApplicationWasObserved::class => self::OBSERVED,
            AdmissionWasDecided::class => self::DECIDED,
            default => throw new CorruptHistoryException(),
        };
        self::keys($p, $keys);
        self::id($p["decision_id"]);
        if ($class === AdmissionApplicationWasObserved::class) {
            $a = $p["application"];
            self::keys($a, ["id", "person_id", "program_id", "entry_term_id", "status", "version"]);
            foreach (["id", "person_id", "program_id", "entry_term_id"] as $key) {
                self::id($a[$key]);
            }
            if ($a["status"] !== "submitted" || !is_int($a["version"]) || $a["version"] < 0) {
                throw new CorruptHistoryException();
            }
            return;
        }
        self::id($p["previous_decision_id"]);
        if (
            $p["previous_decision_id"] === $p["decision_id"] ||
            !in_array($p["status"], ["accepted", "rejected"], true) ||
            !is_int($p["application_version"]) ||
            $p["application_version"] < 1
        ) {
            throw new CorruptHistoryException();
        }
        self::source($p["source"]);
    }
    public static function source(mixed $s): void
    {
        self::keys($s, ["program_code", "program_title", "entry_term_code", "submission"]);
        foreach (["program_code", "program_title", "entry_term_code"] as $key) {
            self::label($s[$key]);
        }
        if ($s["submission"] === null) {
            return;
        }
        $r = $s["submission"];
        self::keys($r, ["id", "form_id", "form_version", "form_name", "fingerprint"]);
        self::id($r["id"]);
        self::id($r["form_id"]);
        self::label($r["form_name"]);
        if (
            !is_int($r["form_version"]) ||
            $r["form_version"] < 0 ||
            !is_string($r["fingerprint"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $r["fingerprint"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** Exclude submission fingerprints and private event/context fields from ordinary staff responses.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public static function displaySource(array $source): array
    {
        self::source($source);
        $r = $source["submission"];
        return [
            "program_code" => $source["program_code"],
            "program_title" => $source["program_title"],
            "entry_term_code" => $source["entry_term_code"],
            "submission" =>
                $r === null ? null : ["form_name" => $r["form_name"], "form_version" => $r["form_version"]],
        ];
    }
    /** @return array<string,mixed> */
    public static function facts(AdmissionApplication $a): array
    {
        return [
            "id" => $a->id->value,
            "person_id" => $a->personId->value,
            "program_id" => $a->programId->value,
            "entry_term_id" => $a->entryTermId->value,
            "status" => $a->status->value,
            "version" => $a->version,
        ];
    }
    /** @param list<string> $keys */
    private static function keys(mixed $p, array $keys): void
    {
        if (!is_array($p) || count($p) !== count($keys) || array_diff(array_keys($p), $keys) !== []) {
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
    private static function label(mixed $v): void
    {
        if (
            !is_string($v) ||
            !mb_check_encoding($v, "UTF-8") ||
            trim($v) === "" ||
            mb_strlen($v) > 500 ||
            preg_match('/[\x00-\x1F\x7F]/', $v)
        ) {
            throw new CorruptHistoryException();
        }
    }
}
