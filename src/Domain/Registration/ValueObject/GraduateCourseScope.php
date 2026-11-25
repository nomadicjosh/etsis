<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\DegreeAudit\ValueObject\GpaTotals;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen eligibility evidence; neither a later GPA nor today's academic labels can replace these facts. */
final readonly class GraduateCourseScope implements \JsonSerializable
{
    public const array IDS = [
        "student_id",
        "person_id",
        "pursuit_id",
        "program_id",
        "program_version_id",
        "academic_year_id",
        "section_id",
        "section_version_id",
        "course_id",
        "course_version_id",
        "policy_publication_id",
        "undergraduate_level_id",
        "graduate_level_id",
    ];
    public const array FIELDS = [
        ...self::IDS,
        "policy_version",
        "advisor_revision",
        "gpa",
        "gpa_source_hash",
        "minimum_gpa_milli",
        "minimum_graduate_grade_milli",
        "graduate_grade_source_hash",
        "graduate_grades",
        "calculation_reference",
        "gpa_sources",
    ];

    public array $data;

    public function __construct(#[\SensitiveParameter] array $data)
    {
        try {
            if (count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []) {
                throw new \InvalidArgumentException();
            }
            foreach (self::IDS as $id) {
                if (!is_string($data[$id])) {
                    throw new \InvalidArgumentException();
                }
                new EntityId($data[$id]);
            }
            foreach (["advisor_revision", "gpa_source_hash", "graduate_grade_source_hash"] as $hash) {
                if (!is_string($data[$hash]) || preg_match('/^[a-f0-9]{64}$/D', $data[$hash]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            if (
                !is_int($data["policy_version"]) ||
                $data["policy_version"] < 1 ||
                $data["policy_version"] > 2000 ||
                !is_int($data["minimum_gpa_milli"]) ||
                $data["minimum_gpa_milli"] < 0 ||
                $data["minimum_gpa_milli"] > 10000 ||
                ($data["minimum_graduate_grade_milli"] !== null &&
                    (!is_int($data["minimum_graduate_grade_milli"]) ||
                        $data["minimum_graduate_grade_milli"] < 0 ||
                        $data["minimum_graduate_grade_milli"] > 10000)) ||
                !is_array($data["gpa"]) ||
                count($data["gpa"]) !== 2 ||
                array_diff(array_keys($data["gpa"]), ["quality_milli", "credits_milli"]) !== [] ||
                !is_int($data["gpa"]["quality_milli"]) ||
                !is_int($data["gpa"]["credits_milli"])
            ) {
                throw new \InvalidArgumentException();
            }
            if (
                new GpaTotals($data["gpa"]["quality_milli"], $data["gpa"]["credits_milli"])->meetsMinimum(
                    $data["minimum_gpa_milli"],
                ) !== true
            ) {
                throw new \InvalidArgumentException();
            }
            if (
                !is_array($data["graduate_grades"]) ||
                !array_is_list($data["graduate_grades"]) ||
                count($data["graduate_grades"]) > 200 ||
                ($data["minimum_graduate_grade_milli"] === null && $data["graduate_grades"] !== [])
            ) {
                throw new \InvalidArgumentException();
            }
            $seen = [];
            foreach ($data["graduate_grades"] as &$grade) {
                $fields = [
                    "credit_id",
                    "course_id",
                    "course_version_id",
                    "grade_id",
                    "grade_points_milli",
                    "source_position",
                ];
                if (
                    !is_array($grade) ||
                    count($grade) !== 6 ||
                    array_diff(array_keys($grade), $fields) !== []
                ) {
                    throw new \InvalidArgumentException();
                }
                foreach (array_slice($fields, 0, 4) as $field) {
                    new EntityId($grade[$field]);
                }
                if (
                    isset($seen[$grade["credit_id"]]) ||
                    !is_int($grade["source_position"]) ||
                    $grade["source_position"] < 1 ||
                    !is_int($grade["grade_points_milli"]) ||
                    $grade["grade_points_milli"] > 10000 ||
                    $grade["grade_points_milli"] < $data["minimum_graduate_grade_milli"]
                ) {
                    throw new \InvalidArgumentException();
                }
                $seen[$grade["credit_id"]] = true;
                $grade = array_replace(array_fill_keys($fields, null), $grade);
            }
            unset($grade);
            if (
                $data["graduate_grade_source_hash"] !==
                GraduateRegistrationRules::hash($data["graduate_grades"])
            ) {
                throw new \InvalidArgumentException();
            }
            $reference = $data["calculation_reference"];
            if (
                !is_array($reference) ||
                count($reference) !== 3 ||
                array_diff(array_keys($reference), ["template_id", "version_id", "version"]) !== [] ||
                !is_int($reference["version"]) ||
                $reference["version"] < 1
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($reference["template_id"]);
            new EntityId($reference["version_id"]);
            if (
                !is_array($data["gpa_sources"]) ||
                !array_is_list($data["gpa_sources"]) ||
                $data["gpa_sources"] === [] ||
                count($data["gpa_sources"]) > 200
            ) {
                throw new \InvalidArgumentException();
            }
            $sourceIds = [];
            foreach ($data["gpa_sources"] as $source) {
                $keys = [
                    "credit_id",
                    "source_id",
                    "section_id",
                    "academic_version",
                    "registration_version",
                    "pursuit_version",
                ];
                if (
                    !is_array($source) ||
                    count($source) !== 6 ||
                    array_diff(array_keys($source), $keys) !== []
                ) {
                    throw new \InvalidArgumentException();
                }
                foreach (array_slice($keys, 0, 3) as $key) {
                    new EntityId($source[$key]);
                }
                foreach (array_slice($keys, 3) as $key) {
                    if (!is_int($source[$key]) || $source[$key] < 1) {
                        throw new \InvalidArgumentException();
                    }
                }
                if (isset($sourceIds[$source["credit_id"]])) {
                    throw new \InvalidArgumentException();
                }
                $sourceIds[$source["credit_id"]] = true;
            }
            $data["gpa"] = [
                "quality_milli" => $data["gpa"]["quality_milli"],
                "credits_milli" => $data["gpa"]["credits_milli"],
            ];
            $this->data = array_replace(array_fill_keys(self::FIELDS, null), $data);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /** GPA and earlier course results are freshly checked at registration; approvals bind the immutable context. */
    public function binding(): array
    {
        return array_intersect_key(
            $this->data,
            array_flip([
                ...self::IDS,
                "policy_version",
                "advisor_revision",
                "minimum_gpa_milli",
                "minimum_graduate_grade_milli",
            ]),
        );
    }

    public function __debugInfo(): array
    {
        return ["evidence" => "[protected graduate-course eligibility]"];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
