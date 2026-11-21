<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\ValueObject\StandingPeriods;
use Throwable;

/** Frozen final-grade evidence for the current enrolled/withdrawn roster; no working marks or personal names. */
final readonly class GradeCertificationSnapshot implements \JsonSerializable
{
    public bool $historyGaps;
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            self::keys($data, ["section", "as_of_date", "rows"]);
            self::keys($data["section"], [
                "id",
                "code",
                "course_id",
                "course_code",
                "course_title",
                "term_id",
                "term_code",
                "offering_version_id",
            ]);
            foreach (["id", "course_id", "term_id"] as $key) {
                new EntityId($data["section"][$key]);
            }
            foreach (["code", "course_code", "course_title", "term_code"] as $key) {
                if (
                    !is_string($data["section"][$key]) ||
                    !mb_check_encoding($data["section"][$key], "UTF-8") ||
                    trim($data["section"][$key]) === "" ||
                    mb_strlen($data["section"][$key]) > 400
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($data["section"]["offering_version_id"] !== null) {
                new EntityId($data["section"]["offering_version_id"]);
            }
            StandingPeriods::date($data["as_of_date"]);
            if (
                !array_is_list($data["rows"]) ||
                count($data["rows"]) < 1 ||
                count($data["rows"]) > 50
            ) {
                throw new CorruptHistoryException();
            }
            $gaps = $data["section"]["offering_version_id"] === null;
            $seen = [];
            foreach ($data["rows"] as $row) {
                self::keys($row, [
                    "student_id",
                    "person_id",
                    "enrollment_id",
                    "enrollment_version",
                    "credit_history_version",
                    "credit",
                    "grade",
                    "section_version_id",
                    "earlier_history_unknown",
                ]);
                foreach (["student_id", "person_id", "enrollment_id"] as $key) {
                    new EntityId($row[$key]);
                }
                if (
                    isset($seen[$row["student_id"]]) ||
                    !is_int($row["enrollment_version"]) ||
                    $row["enrollment_version"] < 1 ||
                    !is_int($row["credit_history_version"]) ||
                    $row["credit_history_version"] < 1 ||
                    !is_bool($row["earlier_history_unknown"])
                ) {
                    throw new CorruptHistoryException();
                }
                $seen[$row["student_id"]] = true;
                $credit = new CreditSnapshot($row["credit"]);
                $grade = new GradeSnapshot($row["grade"]);
                if (
                    $credit->facts["student_id"] !== $row["student_id"] ||
                    $credit->facts["source_id"] !== $row["enrollment_id"] ||
                    $credit->facts["credit_type"] !== "institutional" ||
                    !in_array($credit->facts["status"], ["A", "N", "W"], true) ||
                    $credit->facts["grade_id"] !== $grade->facts["id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $quantities = $grade->quantities(
                    $credit->facts["attempted_milli"],
                    $credit->facts["status"],
                );
                foreach ($quantities as $key => $value) {
                    if ($credit->facts[$key] !== $value) {
                        throw new CorruptHistoryException();
                    }
                }
                if ($row["section_version_id"] !== null) {
                    new EntityId($row["section_version_id"]);
                }
                $gaps = $gaps ||
                $row["earlier_history_unknown"] ||
                $row["section_version_id"] === null ||
                $credit->facts["course_version_id"] === null ||
                $grade->facts["scale_version_id"] === null;
            }
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 55000) {
                throw new CorruptHistoryException();
            }
            $this->historyGaps = $gaps;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public static function keys(array $data, array $keys): void
    {
        if (count($data) !== count($keys) || array_diff(array_keys($data), $keys) !== []) {
            throw new CorruptHistoryException();
        }
    }
    public static function hash(array $data): string
    {
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map(
                static fn(mixed $item): mixed => is_array($item) ? $canonical($item) : $item,
                $value,
            );
        };
        return hash(
            "sha256",
            json_encode(
                $canonical($data),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
    /** Date is evidence of this observation, not a source change that invalidates a prior certificate tomorrow. */
    public function fingerprint(): string
    {
        return self::hash(array_diff_key($this->data, ["as_of_date" => true]));
    }
    public function jsonSerialize(): array
    {
        return ["certification" => "[restricted final-grade evidence]"];
    }
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
