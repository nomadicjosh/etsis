<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\PolicyAttempt;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\DegreeAudit\ValueObject\RepeatGpaPolicy;
use Domain\DegreeAudit\ValueObject\TransferGpaPolicy;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;

/** Resolve chronology only for competing GPA attempts that actually require academic-period ordering. */
final readonly class RetainedAttemptReader
{
    public function __construct(private RetainedSectionReader $sections)
    {
    }

    /** Internal inputs already authorized/verified under the caller's student lock.
     * @return list<PolicyAttempt>
     */
    public function forPolicy(
        AcademicInputSet $inputs,
        AuditCalculationPolicy $policy,
        HistoryReadBudget $budget,
    ): array {
        $attempts = array_map(PolicyAttempt::fromAcademicWork(...), $inputs->items);
        if (
            $policy->gpa !== RepeatGpaPolicy::LatestEligible ||
            $policy->attemptOrder !== RepeatAttemptOrder::AcademicPeriod
        ) {
            return $attempts;
        }
        $competing = [];
        foreach ($attempts as $a) {
            if (
                $a->grade !== null &&
                $a->grade->facts["counts_gpa"] &&
                $a->attemptedMilli > 0 &&
                !in_array($a->status, ["D", "V"], true) &&
                $policy->gradeScaleVersion !== null &&
                $policy->acceptsGradingPublication($a->grade->facts["scale_version_id"]) &&
                !($a->origin === "transfer" && $policy->transferGpa !== TransferGpaPolicy::IncludeEligible)
            ) {
                $competing[$a->courseId->value][] = $a;
            }
        }
        $work = array_column($inputs->items, null, "id");
        $sources = array_column($inputs->references, null, "credit_id");
        $references = [];
        $selected = [];
        foreach ($competing as $course) {
            if (count($course) < 2) {
                continue;
            }
            foreach ($course as $a) {
                $item = $work[$a->id->value];
                $version = $item["section_version_id"];
                if ($a->origin !== "institutional" || $version === null) {
                    continue;
                }
                $section = $sources[$a->id->value]["section_id"] ?? null;
                if (!is_string($section) || !is_string($version)) {
                    throw new CorruptHistoryException();
                }
                $references[] = ["section_id" => $section, "version_id" => $version];
                $selected[$a->id->value] = ["section_id" => $section, "version_id" => $version];
            }
        }
        $periods = $this->sections->periods($references, $budget);
        foreach ($attempts as $i => $a) {
            if (!isset($selected[$a->id->value])) {
                continue;
            }
            $reference = $selected[$a->id->value];
            $period = $periods[$reference["section_id"]][$reference["version_id"]];
            $item = $work[$a->id->value];
            if (
                $period["course_id"] !== $a->courseId->value ||
                $period["term_id"] !== $item["term_id"] ||
                $period["course_version_id"] !== $item["course_version_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $attempts[$i] = PolicyAttempt::fromAcademicWork($item, $period["starts_on"]);
        }
        return $attempts;
    }
}
