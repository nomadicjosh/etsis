<?php

declare(strict_types=1);

namespace Domain\Graduation\ValueObject;

use DateTimeImmutable;
use Domain\DegreeAudit\ValueObject\DegreeProgressSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Strict protected decision schema. Ordinary APIs receive only curated summaries. */
final class GraduationSchema
{
    public const array FIELDS = [
        "decision_id",
        "previous_decision_id",
        "student_id",
        "identity",
        "action",
        "details",
        "revision",
        "intent",
    ];

    public static function validate(array $p): void
    {
        self::keys($p, self::FIELDS);
        new EntityId($p["decision_id"]);
        new EntityId($p["student_id"]);
        self::keys($p["identity"], [
            "person_id",
            "core_version",
            "history_recorded",
            "earlier_history_unknown",
        ]);
        new EntityId($p["identity"]["person_id"]);
        if (
            !is_int($p["identity"]["core_version"]) ||
            $p["identity"]["core_version"] < 0 ||
            !is_bool($p["identity"]["history_recorded"]) ||
            !is_bool($p["identity"]["earlier_history_unknown"]) ||
            $p["identity"]["history_recorded"] !==
                $p["identity"]["core_version"] > 0
        ) {
            throw new CorruptHistoryException();
        }

        if ($p["previous_decision_id"] !== null) {
            new EntityId($p["previous_decision_id"]);
        }
        foreach (["revision", "intent"] as $key) {
            if (
                !is_string($p[$key]) ||
                preg_match('/^[a-f0-9]{64}$/D', $p[$key]) !== 1
            ) {
                throw new CorruptHistoryException();
            }
        }
        $d = $p["details"];
        if (!is_array($d)) {
            throw new CorruptHistoryException();
        }
        switch ($p["action"]) {
            case "eligibility":
                self::keys($d, [
                    "proposal",
                    "snapshot",
                    "attested",
                    "history_acknowledged",
                    "eligible",
                ]);
                self::proposal($d["proposal"]);
                $snapshot = new DegreeProgressSnapshot($d["snapshot"]);
                if (
                    !is_bool($d["attested"]) ||
                    !is_bool($d["history_acknowledged"]) ||
                    !is_bool($d["eligible"]) ||
                    $p["student_id"] !== $snapshot->data["student_id"] ||
                    $d["proposal"]["awarded_on"] !==
                        $snapshot->data["as_of_date"] ||
                    $d["eligible"] !==
                        self::eligible(
                            $snapshot,
                            $d["attested"],
                            $d["history_acknowledged"],
                        )
                ) {
                    throw new CorruptHistoryException();
                }
                break;
            case "confer":
                self::keys($d, ["eligibility_id", "credential"]);
                new EntityId($d["eligibility_id"]);
                self::credential($d["credential"]);
                break;
            case "correct":
                self::keys($d, ["credential"]);
                self::credential($d["credential"]);
                break;
            case "revoke":
                self::keys($d, ["credential_id"]);
                new EntityId($d["credential_id"]);
                break;
            default:
                throw new CorruptHistoryException();
        }
    }

    public static function proposal(array $p): void
    {
        self::keys($p, ["title", "awarded_on", "term_id", "honors"]);
        self::text($p["title"], 200);
        self::text($p["honors"], 200, true);
        new EntityId($p["term_id"]);
        $date = is_string($p["awarded_on"])
        ? DateTimeImmutable::createFromFormat("!Y-m-d", $p["awarded_on"])
        : false;
        if ($date === false || $date->format("Y-m-d") !== $p["awarded_on"]) {
            throw new CorruptHistoryException();
        }
    }

    public static function credential(array $c): void
    {
        self::keys($c, ["id", "proposal", "program", "term", "components"]);
        new EntityId($c["id"]);
        self::proposal($c["proposal"]);
        self::keys($c["program"], ["id", "code", "title"]);
        new EntityId($c["program"]["id"]);
        self::text($c["program"]["code"], 100);
        self::text($c["program"]["title"], 300);
        self::keys($c["term"], ["id", "code"]);
        new EntityId($c["term"]["id"]);
        self::text($c["term"]["code"], 100);
        if (
            $c["term"]["id"] !== $c["proposal"]["term_id"] ||
            !array_is_list($c["components"]) ||
            count($c["components"]) > 25
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($c["components"] as $component) {
            self::keys($component, ["kind", "code", "title"]);
            if (
                !in_array(
                    $component["kind"],
                    ["degree", "specialization"],
                    true,
                )
            ) {
                throw new CorruptHistoryException();
            }
            self::text($component["code"], 100);
            self::text($component["title"], 300);
        }
    }

    public static function curriculum(DegreeProgressSnapshot $snapshot): array
    {
        $requirements = $snapshot->data["components"][0]["requirements"];
        $components = [];
        foreach ($snapshot->data["components"] as $component) {
            $r = $component["requirements"];
            $label =
            $component["kind"] === "degree"
            ? [
                        "code" => $r["context"]["program_code"],
                        "title" => $r["definition"]["name"],
                    ]
            : $r["specialization"];
            $components[] = [
                "kind" => $component["kind"],
                "code" => $label["code"],
                "title" => $label["title"],
            ];
        }
        return [
            "program" => [
                "id" => $requirements["program_id"],
                "code" => $requirements["context"]["program_code"],
                "title" => $requirements["context"]["program_title"],
            ],
            "components" => $components,
        ];
    }

    public static function eligible(
        DegreeProgressSnapshot $snapshot,
        bool $attested,
        bool $acknowledged,
    ): bool {
        $r = $snapshot->data["result"];
        if (!in_array($r['input_status'], ['verified', 'history_gaps'], true)) {
            return false;
        }
        if (
            !$attested ||
            !in_array($r["input_status"], ["verified", "history_gaps"], true) ||
            $r["outcome"] !== "feasible" ||
            ($r["input_status"] !== "verified" && !$acknowledged)
        ) {
            return false;
        }
        foreach ($r["components"] as $component) {
            foreach ($component["progress"]["minimums"] as $minimum) {
                if (
                    !in_array(
                        $minimum["status"],
                        ["satisfied", "not_required"],
                        true,
                    )
                ) {
                    return false;
                }
            }
            if ($component["progress"]["issues"] !== []) {
                return false;
            }
        }
        return true;
    }

    public static function keys(array $p, array $keys): void
    {
        if (
            count($p) !== count($keys) ||
            array_diff(array_keys($p), $keys) !== []
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function text(
        mixed $text,
        int $max,
        bool $empty = false,
    ): void {
        if (
            !is_string($text) ||
            !mb_check_encoding($text, "UTF-8") ||
            mb_strlen($text) > $max ||
            (!$empty && trim($text) === "") ||
            trim($text) !== $text ||
            preg_match('/[\x00-\x1f\x7f]/', $text)
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function hash(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = self::canonical($item);
                }
            }
            unset($item);
        }
        return hash("sha256", json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }
}
