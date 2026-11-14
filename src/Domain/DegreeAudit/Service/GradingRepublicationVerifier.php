<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\Service\RetainedGradingReader;
use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\Gradebook\ValueObject\GradingScaleSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Recognize unchanged re-publications of one scale; no equivalence between different policies or units. */
final readonly class GradingRepublicationVerifier
{
    public function __construct(private RetainedGradingReader $publications)
    {
    }

    /** Caller authorizes and holds the student read boundary. Proofs live only in this request.
     * @param list<array<string,mixed>> $work
     * @param array<string,mixed> $requirements
     * @param array<string,array<string,mixed>> $groupMinimums
     * @return array<string,mixed> */
    public function prepare(
        #[\SensitiveParameter] array $work,
        #[\SensitiveParameter] array $requirements,
        HistoryReadBudget $budget,
        array $groupMinimums = [],
        bool $gpaChecks = true,
    ): array {
        if (count($work) > 200 || count($groupMinimums) > 25) {
            throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
        }
        // Discard any caller's stale/transplanted runtime proof before constructing a new one.
        unset($requirements["identical_grading_publications"]);
        $scale = $requirements["grade_scale"] ?? null;
        if ($scale === null || ($scale["version_id"] ?? null) === null || ($scale["is_default"] ?? true)) {
            return $requirements;
        }
        new GradingScaleSnapshot($scale);
        $policy = AuditCalculationPolicy::fromRequirements($requirements);
        $groupGradeFloors = array_any(
            $groupMinimums,
            static fn(array $g): bool => ($g["min_course_grade_points_milli"] ?? 0) > 0,
        );
        $identity = $scale["source"] === "program" ? $scale["program_id"] : GradingScaleService::GLOBAL_ID;
        $requested = [];
        foreach ($work as $item) {
            $grade = $item["grade"];
            $gpa =
            $gpaChecks &&
            $policy->gpa !== null &&
            ($item["credit_type"] !== "transfer" ||
            $policy->transferGpa === TransferGpaPolicy::IncludeEligible) &&
            $item["attempted_milli"] > 0 &&
            ($grade["counts_gpa"] ?? false);
            $floor = $groupGradeFloors && ($grade["passes"] ?? false) && $item["status"] !== "W";
            if (
                $grade === null ||
                in_array($item["status"], ["D", "V"], true) ||
                (!$gpa && !$floor) ||
                $grade["scale_id"] !== $identity ||
                $grade["scale_version_id"] === null ||
                $grade["scale_version_id"] === $scale["version_id"]
            ) {
                continue;
            }
            $requested[$grade["scale_version_id"]] = new EntityId($grade["scale_version_id"]);
        }
        if ($requested === []) {
            return $requirements;
        }
        $definitions = $this->publications->selected(
            new EntityId($identity),
            [new EntityId($scale["version_id"]), ...array_values($requested)],
            $budget,
        );
        $primary = $definitions[$scale["version_id"]];
        if (!$primary->samePolicy(new GradingScaleDefinition($scale["definition"]))) {
            throw new CorruptHistoryException();
        }
        $same = [];
        foreach ($requested as $id => $version) {
            if ($primary->samePolicy($definitions[$id])) {
                $same[$id] = true;
            }
        }
        // A retained award must describe a grade actually belonging to the verified definition.
        $grades = array_column($primary->definition["grades"], null, "code");
        foreach ($work as $item) {
            $grade = $item["grade"];
            if ($grade === null || !isset($same[$grade["scale_version_id"] ?? ""])) {
                continue;
            }
            $retained = $grades[$grade["code"]] ?? null;
            if ($grade["scale_id"] !== $identity || $retained === null) {
                throw new CorruptHistoryException();
            }
            foreach (["points_milli", "passes", "counts_gpa"] as $key) {
                if ($grade[$key] !== $retained[$key]) {
                    throw new CorruptHistoryException();
                }
            }
        }
        $requirements["identical_grading_publications"] = $same;
        return $requirements;
    }
}
