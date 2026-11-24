<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\HumanResources\Repository\EmploymentProjectionVerifier;
use Domain\HumanResources\Repository\PrimaryPositionRepository;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\HumanResources\Service\PositionLedger;
use Domain\HumanResources\Service\PrimaryPositionLedger;
use Domain\HumanResources\Service\TimeDecisionLedger;
use Domain\HumanResources\ValueObject\EmploymentProjectionCheck;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** No locks, business writes, eligibility recalculation or repair. Refuses enclosing snapshots. */
final readonly class SqlEmploymentProjectionVerifier implements EmploymentProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private PrimaryPositionRepository $primaryRows,
        private TimeRepository $times,
        private PrimaryPositionLedger $primary,
        private PositionLedger $positions,
        private TimeDecisionLedger $decisions,
    ) {
    }

    public function primaryPosition(
        EntityId $employee,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck {
        return $this->verify(
            "employment_primary_position",
            $employee,
            $eventLimit,
        );
    }

    public function position(
        EntityId $position,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck {
        return $this->verify("employment_position", $position, $eventLimit);
    }

    public function timeEntry(
        EntityId $entry,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck {
        return $this->verify("timesheet_decision", $entry, $eventLimit);
    }

    private function verify(
        string $scope,
        EntityId $id,
        int $eventLimit,
    ): EmploymentProjectionCheck {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException(
                "Use an employment verification allowance of 1–2000 events.",
            );
        }
        if ($this->sql->inTransaction()) {
            return new EmploymentProjectionCheck(
                "unverified",
                $scope,
                $id,
                0,
                issues: ["unavailable"],
            );
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $status = "unverified";
        $position = $records = null;
        $issues = [];
        $issue = match ($scope) {
            "employment_primary_position" => "primary_position",
            "employment_position" => "position",
            "timesheet_decision" => "time_decision",
        };
        try {
            if ($this->sql->tableExists("event_store")) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($scope, $id);
            if (
                count($before["employee"]) !== 1 ||
                count($before["person"]) !== 1
            ) {
                $issues = ["identity"];
            } else {
                $employee = new EntityId($before["employee"][0]["id"]);
                $position = $this->positionOf($before["head"]);
                if ($position > $eventLimit) {
                    throw new HistoryReadLimitException();
                }
                if ($scope === "employment_primary_position") {
                    $root = $this->primary->inspect($employee, budget: $budget)['root'];
                    if ($root->playhead() !== $position) {
                        throw new CorruptHistoryException();
                    }
                    // Verify the selected agreement without treating a later amendment/end as journal damage.
                    $this->primary->summary($root->designation(), $budget);
                    $records = $position === 0 ? 0 : 1;
                    $status = $position === 0 ? "observed_empty" : "verified";
                } elseif ($scope === "employment_position") {
                    $record = $this->positions->verified(
                        $employee,
                        $id,
                        $budget,
                    );
                    if ($record->version !== $position || $position === 0) {
                        throw new CorruptHistoryException();
                    }
                    $records = 1;
                    $status = "verified";
                } else {
                    $entry = $this->times->get($id);
                    if ($entry->employeeId->value !== $employee->value) {
                        throw new CorruptHistoryException();
                    }
                    $decision = $this->decisions->inspect($entry, $budget);
                    if ($decision === null) {
                        if ($position !== 0) {
                            throw new CorruptHistoryException();
                        }
                        $status = $entry->status === 'pending' ? 'observed_empty' : 'history_gaps';
                        $records = $status === "observed_empty" ? 0 : null;
                        $issues = $status === 'history_gaps' ? ['earlier_decision'] : [];
                    } else {
                        if ($position !== 1) {
                            throw new CorruptHistoryException();
                        }
                        $records = 1;
                        $status = "verified";
                    }
                }
                $budget->checkTime();
            }
        } catch (HistoryReadLimitException) {
            $status = "limited";
            $issues = [];
        } catch (CorruptHistoryException) {
            $issues = [$issue];
            $status = "unverified";
        } catch (Throwable) {
            $issues = ["unavailable"];
            $status = "unverified";
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($scope, $id)) {
                    $status = "changed";
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== "changed") {
                    $status = "limited";
                    $issues = [];
                }
            } catch (Throwable) {
                $status = "unverified";
                $issues = ["unavailable"];
            }
        }
        return new EmploymentProjectionCheck(
            $status,
            $scope,
            $id,
            $budget->eventsRead(),
            $position,
            in_array($status, ["verified", "observed_empty"], true)
                ? $records
                : null,
            $issues,
        );
    }

    private function positionOf(array $heads): int
    {
        if ($heads === []) {
            return 0;
        }
        $value = $heads[0]["version"];
        if (
            count($heads) !== 1 ||
            !is_scalar($value) ||
            !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) ||
            (string) (int) $value !== (string) $value ||
            (int) $value > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
        return (int) $value;
    }

    /** Only current routing, selected encrypted projections and relevant heads; never exposed. */
    private function boundary(string $scope, EntityId $id): array
    {
        $primary = $scope === "employment_primary_position";
        $time = $scope === "timesheet_decision";
        $projection = $this->sql->rows(
            $primary
                ? "sis_primary_positions"
                : ($time
                    ? "sis_timesheets"
                    : "sis_employee_positions"),
            [$primary ? "employee_id" : "id" => $id->value],
            2,
        );
        $employee = $primary ? $id->value : ($projection[0]['employee_id'] ?? null);
        $employees = [];
        if ($employee !== null) {
            $employees = $this->routing('sis_employees', ['id', 'person_id'], $employee);
        }
        $person = $employees[0]["person_id"] ?? null;
        $related = [];
        if ($primary) {
            try {
                $selection = $this->primaryRows->find($id)?->selection;
            } catch (CorruptHistoryException) {
                $selection = null;
            }
            if ($selection !== null) {
                $related = [
                    "projection" => $this->sql->rows(
                        "sis_employee_positions",
                        ["id" => $selection["id"]],
                        2,
                    ),
                    "head" => $this->head(
                        "employment_position",
                        $selection["id"],
                    ),
                ];
            }
        } elseif ($time) {
            $related = $this->sql->rows(
                "sis_timesheet_positions",
                ["entry_id" => $id->value],
                2,
            );
        }
        return [
            "projection" => $projection,
            "employee" => $employees,
            "person" =>
                $person === null
                    ? []
                    : $this->routing(
                        "sis_persons",
                        ["id", "owner_user_id"],
                        $person,
                    ),
            "head" => $this->head($scope, $id->value),
            "related" => $related,
        ];
    }

    private function routing(string $table, array $columns, string $id): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, $columns)) .
                " FROM " .
                $q($table) .
                " WHERE " .
                $q("id") .
                " = ?",
            [$id],
            2,
        );
    }

    private function head(string $scope, string $id): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(
                    ", ",
                    array_map($q, [
                        "id",
                        "stream_type",
                        "aggregate_id",
                        "version",
                        "head_hash",
                    ]),
                ) .
                " FROM " .
                $q("sis_history_streams") .
                " WHERE " .
                $q("stream_type") .
                " = ? AND " .
                $q("aggregate_id") .
                " = ?",
            [$scope, $id],
            2,
        );
    }
}
