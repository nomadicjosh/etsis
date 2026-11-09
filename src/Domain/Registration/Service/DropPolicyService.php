<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class DropPolicyService
{
    public function __construct(
        private DropPolicyRepository $policies,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }
    /** @return array<string, mixed> */
    public function show(EntityId $term): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $policy = $this->policies->find($term);
        $windows = $policy["windows"] ?? [["through" => null, "grade_code" => "W"]];
        return [
            "term" => $this->policies->calendar($term),
            "version" => $policy["version"] ?? -1,
            "windows" => $windows,
            "default_policy" => $policy === null,
            "grading" => array_map(
                fn(array $w): ?array => $this->policies->grade($w["grade_code"]),
                $windows,
            ),
        ];
    }
    public function save(EntityId $term, int $version, DropWindows $windows): void
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->transactions->run(function () use ($term, $version, $windows, $actor): void {
            $this->policies->lockTerm($term);
            $calendar = $this->policies->calendar($term);
            foreach ($windows->windows as $window) {
                if (
                    ($window["through"] !== null && $window["through"] <= $calendar["add_drop_ends_on"]) ||
                    $this->policies->grade($window["grade_code"]) === null
                ) {
                    throw new ValidationException([
                        [
                            "field" => "windows",
                            "message" =>
                                "Use cutoffs after the drop/add deadline and existing catalog grade codes.",
                        ],
                    ]);
                }
            }
            $this->policies->save($term, $version, $windows->windows);
            $this->audit->record($actor, "registration.drop_policy_saved", $term);
        });
    }
    /** Public academic policy metadata; callers separately authorize the affected student.
     * @return array<string, mixed>
     */
    public function outcome(EntityId $term, string $effective, ?EntityId $student = null): array
    {
        $calendar = $this->policies->calendar($term);
        $base = ["term_code" => $calendar["code"], "grace_ends_on" => $calendar["add_drop_ends_on"]];
        if ($effective <= $calendar["add_drop_ends_on"]) {
            return $base + [
                "delete_records" => true,
                "grade_code" => null,
                "grade_id" => null,
                "points_milli" => 0,
                "counts_gpa" => false,
                "policy_version" => -1,
            ];
        }
        $policy = $this->policies->find($term);
        if ($policy === null) {
            return $base + [
                "delete_records" => false,
                "grade_code" => "W",
                "grade_id" => null,
                "points_milli" => 0,
                "counts_gpa" => false,
                "policy_version" => -1,
            ];
        }
        $code = new DropWindows($policy["windows"])->gradeOn($effective);
        $grade = $this->policies->grade($code, $student);
        if ($grade === null) {
            throw new ValidationException([
                [
                    "field" => "grade",
                    "message" => "Catalog staff must review this term’s missing drop grade.",
                ],
            ]);
        }
        return $base + [
            "delete_records" => false,
            "grade_code" => $code,
            "grade_id" => $grade["id"],
            "points_milli" => $grade["points_milli"],
            "counts_gpa" => $grade["counts_gpa"],
            "policy_version" => $policy["version"],
        ];
    }
}
