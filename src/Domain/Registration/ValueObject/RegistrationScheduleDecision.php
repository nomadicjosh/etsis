<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Retained inputs to one acceptance, not a guarantee that later timetable publications cannot change. */
final readonly class RegistrationScheduleDecision implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        self::keys($facts, [
            "student_id",
            "target_section_id",
            "policy",
            "plans",
            "enrollments",
            "conflicts",
        ]);
        self::id($facts["student_id"]);
        self::id($facts["target_section_id"]);
        if (!is_array($facts["policy"])) {
            self::invalid();
        }
        self::keys($facts["policy"], ["mode", "revision"]);
        if (!in_array($facts["policy"]["mode"], ["block", "warn"], true)) {
            self::invalid();
        }
        self::version($facts["policy"]["revision"], 0);
        foreach (["plans", "enrollments", "conflicts"] as $field) {
            if (
                !is_array($facts[$field]) ||
                !array_is_list($facts[$field]) ||
                count($facts[$field]) > 1001
            ) {
                self::invalid();
            }
        }
        if (count($facts["conflicts"]) > 100) {
            self::invalid();
        }
        $plans = [];
        foreach ($facts["plans"] as $plan) {
            if (!is_array($plan)) {
                self::invalid();
            }
            self::keys($plan, [
                "section_id",
                "publication_id",
                "version",
                "definition_hash",
            ]);
            self::id($plan["section_id"]);
            if (isset($plans[$plan["section_id"]])) {
                self::invalid();
            }
            if ($plan["publication_id"] === null) {
                if (
                    $plan["version"] !== null ||
                    $plan["definition_hash"] !== null
                ) {
                    self::invalid();
                }
            } else {
                self::id($plan["publication_id"]);
                self::version($plan["version"], 1);
                if (
                    !is_string($plan["definition_hash"]) ||
                    preg_match(
                        '/^[a-f0-9]{64}$/D',
                        $plan["definition_hash"],
                    ) !== 1
                ) {
                    self::invalid();
                }
            }
            $plans[$plan["section_id"]] = $plan;
        }
        if (!isset($plans[$facts["target_section_id"]])) {
            self::invalid();
        }
        $sections = [];
        foreach ($facts["enrollments"] as $enrollment) {
            if (!is_array($enrollment)) {
                self::invalid();
            }
            self::keys($enrollment, ["enrollment_id", "section_id", "version"]);
            self::id($enrollment["enrollment_id"]);
            self::id($enrollment["section_id"]);
            self::version($enrollment["version"], 0);
            if (
                $enrollment["section_id"] === $facts["target_section_id"] ||
                isset($sections[$enrollment["section_id"]]) ||
                !isset($plans[$enrollment["section_id"]])
            ) {
                self::invalid();
            }
            $sections[$enrollment["section_id"]] = true;
        }
        if (count($plans) !== count($sections) + 1) {
            self::invalid();
        }
        $conflicts = [];
        foreach ($facts["conflicts"] as $conflict) {
            if (!is_array($conflict)) {
                self::invalid();
            }
            self::keys($conflict, [
                "section_id",
                "section_code",
                "target_occurrence_id",
                "other_occurrence_id",
                "starts_at",
                "ends_at",
            ]);
            if (
                !is_string($conflict["section_code"]) ||
                trim($conflict["section_code"]) === "" ||
                mb_strlen($conflict["section_code"]) > 191 ||
                preg_match('/[\x00-\x1F\x7F]/', $conflict["section_code"])
            ) {
                self::invalid();
            }
            $itemsToCheck1 = ["section_id", "target_occurrence_id", "other_occurrence_id"];
            foreach ($itemsToCheck1 as $field) {
                self::id($conflict[$field]);
            }
            if (
                !isset($sections[$conflict["section_id"]]) ||
                isset($conflicts[$conflict["section_id"]]) ||
                $plans[$conflict["section_id"]]["publication_id"] === null ||
                $plans[$facts["target_section_id"]]["publication_id"] === null
            ) {
                self::invalid();
            }
            foreach (["starts_at", "ends_at"] as $field) {
                $date = is_string($conflict[$field])
                ? \DateTimeImmutable::createFromFormat(
                    "!Y-m-d\TH:i:s\Z",
                    $conflict[$field],
                    new \DateTimeZone("UTC"),
                )
                : false;
                if (
                    $date === false ||
                    $date->format("Y-m-d\TH:i:s\Z") !== $conflict[$field]
                ) {
                    self::invalid();
                }
            }
            if ($conflict["starts_at"] >= $conflict["ends_at"]) {
                self::invalid();
            }
            $conflicts[$conflict["section_id"]] = true;
        }
    }
    public function blocked(): bool
    {
        return $this->facts["policy"]["mode"] === "block" &&
        $this->facts["conflicts"] !== [];
    }
    public function summary(): array
    {
        $unknown = count(
            array_filter(
                $this->facts["plans"],
                static fn(array $p): bool => $p["publication_id"] === null,
            ),
        );
        return [
            "mode" => $this->facts["policy"]["mode"],
            "blocked" => $this->blocked(),
            "status" =>
                $this->facts["conflicts"] !== []
                    ? "conflict"
                    : ($unknown > 0
                        ? "not_fully_checked"
                        : "clear"),
            "conflict_count" => count($this->facts["conflicts"]),
            "unpublished_count" => $unknown,
        ];
    }
    public function revision(): string
    {
        $normalize = static function (mixed $v) use (&$normalize): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map($normalize, $v);
        };
        return hash(
            "sha256",
            json_encode($normalize($this->facts), JSON_THROW_ON_ERROR),
        );
    }
    private static function keys(array $value, array $keys): void
    {
        if (
            count($value) !== count($keys) ||
            array_diff(array_keys($value), $keys) !== []
        ) {
            self::invalid();
        }
    }
    private static function id(mixed $value): void
    {
        if (!is_string($value)) {
            self::invalid();
        }
        try {
            new EntityId($value);
        } catch (\InvalidArgumentException) {
            self::invalid();
        }
    }
    private static function version(mixed $value, int $min): void
    {
        if (!is_int($value) || $value < $min || $value > 2000000000) {
            self::invalid();
        }
    }
    private static function invalid(): never
    {
        throw new CorruptHistoryException();
    }
    public function __debugInfo(): array
    {
        return ["schedule" => "[protected registration evidence]"];
    }
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
