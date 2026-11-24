<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use DateTimeZone;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\TimeEntry;
use Domain\HumanResources\ValueObject\TimeApprovalRules;
use Domain\HumanResources\ValueObject\TimeReviewEvidence;
use Domain\HumanResources\ValueObject\TimeReviewWeek;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;

/** Internal assessment, authorized by the employee work service. Elapsed time is never pay. */
final readonly class TimeReviewService
{
    public function __construct(
        private TimeRepository $entries,
        private TimeApprovalPolicyService $policies,
        private PrimaryPositionLedger $primaryPositions,
    ) {
    }
    public function capture(
        TimeEntry $target,
        ?HistoryReadBudget $budget = null,
        bool $proposed = false,
        bool $rejection = false,
    ): TimeReviewEvidence {
        $budget ??= new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
        $policy = $this->policies->current($budget);
        $rules = new TimeApprovalRules($policy["rules"]);
        $entries = [$target];
        if ($rules->hasChecks() && !$rejection) {
            $zone = new DateTimeZone($rules->data["timezone"]);
            $first = new TimeReviewWeek(
                $target->work->startsAt->setTimezone($zone)->format("Y-m-d"),
                $zone,
            );
            $last = new TimeReviewWeek(
                $target->work->endsAt
                    ->modify("-1 microsecond")
                    ->setTimezone($zone)
                    ->format("Y-m-d"),
                $zone,
            );
            $entries = $this->entries->inWindow(
                $target->employeeId,
                new TimeSlot($first->startsAt, $last->endsAt),
                201,
            );
            if (count($entries) > ($proposed ? 199 : 200)) {
                throw new ValidationException([
                    [
                        "field" => "time",
                        "message" =>
                            "This time review exceeds two hundred entries across the affected weeks. " .
                            "Ask HR for a detailed review.",
                    ],
                ]);
            }
            if ($proposed) {
                $entries[] = $target;
            }
        }
        $rows = [];
        foreach ($entries as $entry) {
            if ($entry->employeeId->value !== $target->employeeId->value) {
                throw new CorruptHistoryException();
            }
            $rows[] = TimeReviewEvidence::facts($entry);
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        return new TimeReviewEvidence([
            "employee_id" => $target->employeeId->value,
            "target" => TimeReviewEvidence::facts($target),
            "policy" => $policy,
            "sources" => $rows,
            "scope" => $rejection
                ? "rejection"
                : ($rules->hasChecks()
                    ? "affected_weeks"
                    : "selected_entry"),
            ...$rules->requiresPrimaryPosition() && !$rejection
                ? ['primary_position' => $this->primaryPositions->approvalEvidence($target->employeeId, $budget)]
                : [],
        ]);
    }
}
