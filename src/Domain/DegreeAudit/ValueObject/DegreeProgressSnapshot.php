<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Portal\ValueObject\StudentDegreeProgress;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** A reviewed observation, not a credential decision. Frozen basis never enters ordinary responses. */
final readonly class DegreeProgressSnapshot implements \JsonSerializable
{
    public const int MAX_BYTES = 262144;

    public function __construct(#[\SensitiveParameter] public array $data)
    {
        $keys = [
            "student_id",
            "pursuit_id",
            "as_of_date",
            "sharing",
            "context_hash",
            "adjustment_version",
            "components",
            "inputs",
            "source_references",
            "result",
        ];
        if (
            count($data) !== count($keys) ||
            array_diff(array_keys($data), $keys) !== [] ||
            strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_BYTES
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($data["student_id"]);
        new EntityId($data["pursuit_id"]);
        $date = is_string($data["as_of_date"])
        ? \DateTimeImmutable::createFromFormat("!Y-m-d", $data["as_of_date"])
        : false;
        if (
            $date === false ||
            $date->format("Y-m-d") !== $data["as_of_date"] ||
            !in_array($data["sharing"], ["one_component", "multiple_components"], true) ||
            !is_string($data["context_hash"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $data["context_hash"]) !== 1 ||
            !is_int($data["adjustment_version"]) ||
            $data["adjustment_version"] < 0 ||
            !is_array($data["components"]) ||
            !array_is_list($data["components"]) ||
            count($data["components"]) < 1 ||
            count($data["components"]) > 25 ||
            !is_array($data["inputs"]) ||
            !array_is_list($data["inputs"]) ||
            count($data["inputs"]) > 200 ||
            !is_array($data["source_references"]) ||
            !array_is_list($data["source_references"]) ||
            count($data["source_references"]) > 2000 ||
            ($data["result"]["status"] ?? null) !== "review" ||
            count($data["result"]["components"] ?? []) !== count($data["components"]) ||
            ($data["result"]["current_record_count"] ?? null) !== count($data["inputs"]) ||
            ($data["result"]["sharing"] ?? null) !== $data["sharing"]
        ) {
            throw new CorruptHistoryException();
        }
        $seen = [];
        foreach ($data["inputs"] as $input) {
            $attempt = PolicyAttempt::fromAcademicWork($input);
            if (isset($seen[$attempt->id->value])) {
                throw new CorruptHistoryException();
            }
            $seen[$attempt->id->value] = true;
        }
        foreach ($data["components"] as $i => $component) {
            if (
                !in_array($component["kind"] ?? null, ["degree", "specialization"], true) ||
                !is_array($component["effective"] ?? null) ||
                !is_array($component["requirements"] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                GroupWorkEligibility::requiresEvaluationDate($component["requirements"]) &&
                ($component["effective"]["evaluated_on"] ?? null) !== $data["as_of_date"]
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($component["requirements"]["version_id"]);
            if (
                $component["requirements"]["version_id"] !==
                ($data["result"]["components"][$i]["progress"]["context"][
                    "requirements_version_id"
                ] ??
                    null)
            ) {
                throw new CorruptHistoryException();
            }
        }
        new StudentDegreeProgress($data["result"]);
    }

    public function fingerprint(): string
    {
        return hash("sha256", json_encode(self::canonical($this->data), JSON_THROW_ON_ERROR));
    }

    /** No individual historical coursework, reasons, source IDs or raw grammar in ordinary readers. */
    public function summary(): array
    {
        $view = new StudentDegreeProgress($this->data["result"])->describe();
        $components = [];
        foreach ($view["components"] as $component) {
            $p = $component["progress"];
            $groups = [];
            foreach ($p["allocation"]["groups"] as $group) {
                $groups[] = array_intersect_key(
                    $group,
                    array_flip([
                        "name",
                        "requirement_type",
                        "requirement_origins",
                        "requirement_grade_floors",
                        "required_credits_milli",
                        "required_courses",
                        "minimum_grade_points_milli",
                        "work_eligibility",
                        "work_evaluation_context",
                        'work_eligibility_context',
                        "coverage",
                    ]),
                );
            }
            $components[] = [
                "label" => $component["label"],
                "context" => $p["context"],
                "input_status" => $p["input_status"],
                "minimums" => $p["minimums"],
                "gpa" => $p["gpa"],
                "groups" => $groups,
            ];
        }
        return [
            "as_of_date" => $this->data["as_of_date"],
            "outcome" => $view["outcome"],
            "sharing" => $view["sharing"],
            "current_record_count" => $view["current_record_count"],
            "components" => $components,
        ];
    }

    public function matchesResult(#[\SensitiveParameter] array $result): bool
    {
        return self::canonical($this->data["result"]) === self::canonical($result);
    }

    public function jsonSerialize(): array
    {
        return $this->summary();
    }

    public function __debugInfo(): array
    {
        return ["snapshot" => "[protected progress basis]"];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::canonical(...), $value);
    }
}
