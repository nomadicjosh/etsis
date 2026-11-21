<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Retained current registration quantities and exact accepted load policy; no inferred census. */
final readonly class LoadDeterminationSnapshot
{
    public int $creditsMilli;
    public ?array $classification;
    public const array FIELDS = [
        "student_id",
        "pursuit_id",
        "term",
        "program",
        "as_of_date",
        "policy",
        "sources",
    ];

    public function __construct(public array $data)
    {
        try {
            self::keys($data, self::FIELDS);
            foreach (["student_id", "pursuit_id"] as $key) {
                new EntityId($data[$key]);
            }
            self::keys($data["term"], ["id", "code", "starts_on", "ends_on"]);
            self::keys($data["program"], ["id", "code", "title"]);
            foreach (["term", "program"] as $key) {
                new EntityId($data[$key]["id"]);
                self::label($data[$key]["code"]);
            }
            self::label($data["program"]["title"]);
            foreach (["as_of_date"] as $key) {
                \Domain\Student\ValueObject\StandingPeriods::date($data[$key]);
            }
            foreach (["starts_on", "ends_on"] as $key) {
                StandingPeriods::date($data["term"][$key]);
            }
            if (
                $data["as_of_date"] < $data["term"]["starts_on"] ||
                $data["as_of_date"] > $data["term"]["ends_on"]
            ) {
                throw new CorruptHistoryException();
            }
            self::keys($data["policy"], ["source", "version_id", "definition"]);
            if (!in_array($data["policy"]["source"], ["global", "program"], true)) {
                throw new CorruptHistoryException();
            }
            new EntityId($data["policy"]["version_id"]);
            $bands = new EnrollmentLoadBands($data["policy"]["definition"]);
            if (
                $bands->definition !== $data["policy"]["definition"] ||
                !array_is_list($data["sources"]) ||
                count($data["sources"]) > 50
            ) {
                throw new CorruptHistoryException();
            }
            $total = 0;
            $enrollments = $credits = [];
            foreach ($data["sources"] as $source) {
                self::keys($source, [
                    "enrollment_id",
                    "enrollment_version",
                    "credit_id",
                    "credit_version",
                    "credits_milli",
                    "course_code",
                    "course_title",
                ]);
                foreach (["enrollment_id", "credit_id"] as $key) {
                    new EntityId($source[$key]);
                }
                foreach (["enrollment_version", "credit_version"] as $key) {
                    if (!is_int($source[$key]) || $source[$key] < 1 || $source[$key] > 2000) {
                        throw new CorruptHistoryException();
                    }
                }
                if (
                    isset($enrollments[$source["enrollment_id"]]) ||
                    isset($credits[$source["credit_id"]]) ||
                    !is_int($source["credits_milli"]) ||
                    $source["credits_milli"] < 0 ||
                    $source["credits_milli"] > 999999
                ) {
                    throw new CorruptHistoryException();
                }
                self::label($source["course_code"]);
                self::label($source["course_title"]);
                $enrollments[$source["enrollment_id"]] = $credits[$source["credit_id"]] = true;
                $total += $source["credits_milli"];
            }
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 55000) {
                throw new CorruptHistoryException();
            }
            $this->creditsMilli = $total;
            $this->classification = $data["sources"] === [] ? null : $bands->classify($total);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    private static function keys(array $data, array $fields): void
    {
        if (count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== []) {
            throw new CorruptHistoryException();
        }
    }
    private static function label(mixed $label): void
    {
        if (
            !is_string($label) ||
            trim($label) === "" ||
            !mb_check_encoding($label, "UTF-8") ||
            mb_strlen($label) > 191 ||
            preg_match('/[\x00-\x1f\x7f]/', $label)
        ) {
            throw new CorruptHistoryException();
        }
    }
    public function fingerprint(): string
    {
        return hash("sha256", json_encode(self::normalize($this->data), JSON_THROW_ON_ERROR));
    }
    private static function normalize(array $data): array
    {
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            }
        }
        return $data;
    }
    public function view(): array
    {
        return [
            "term" => $this->data["term"],
            "program" => $this->data["program"],
            "as_of_date" => $this->data["as_of_date"],
            "basis" => "active_registered_credits",
            "credits_milli" => $this->creditsMilli,
            "enrollment_count" => count($this->data["sources"]),
            "classification" => $this->classification,
            "policy" => $this->data["policy"],
            "items" => array_map(
                static fn(array $s): array => array_intersect_key(
                    $s,
                    array_flip(["course_code", "course_title", "credits_milli"]),
                ),
                $this->data["sources"],
            ),
        ];
    }
}
