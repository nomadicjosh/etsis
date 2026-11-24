<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Value-free selected-history observation, never current appointment eligibility or payroll approval. */
final readonly class EmploymentProjectionCheck
{
    public const array REFERENCES = [
        "employment_primary_position" => "primary_position_reference",
        "employment_position" => "position_reference",
        "timesheet_decision" => "time_entry_reference",
    ];

    public function __construct(
        public string $status,
        public string $scope,
        public EntityId $reference,
        public int $events,
        public ?int $position = null,
        public ?int $records = null,
        public array $issues = [],
    ) {
        $complete = in_array($status, ["verified", "observed_empty"], true);
        if (
            !array_key_exists($scope, self::REFERENCES) ||
            !in_array(
                $status,
                [
                    "verified",
                    "observed_empty",
                    "history_gaps",
                    "unverified",
                    "changed",
                    "limited",
                ],
                true,
            ) ||
            $events < 0 ||
            $events > 2000 ||
            ($position !== null && $position < 0) ||
            $complete !== ($records !== null) ||
            ($complete && $position === null) ||
            ($records !== null && !in_array($records, [0, 1], true)) ||
            ($status === "verified" && ($position < 1 || $records !== 1)) ||
            ($status === 'verified' && $scope === 'timesheet_decision' && $position !== 1) ||
            ($status === "observed_empty" &&
                ($scope === "employment_position" ||
                    $position !== 0 ||
                    $records !== 0)) ||
            ($status === "history_gaps" &&
                ($scope !== "timesheet_decision" || $position !== 0)) ||
            !array_is_list($issues) ||
            array_diff($issues, [
                "identity",
                "primary_position",
                "position",
                "time_decision",
                "earlier_decision",
                "unavailable",
            ]) !== [] ||
            (in_array($status, ["unverified", "history_gaps"], true)
                ? $issues === []
                : $issues !== [])
        ) {
            throw new InvalidArgumentException(
                "Use a bounded, consistent employment verification result.",
            );
        }
    }

    public function describe(): array
    {
        return [
            "status" => $this->status,
            "scope" => $this->scope,
            self::REFERENCES[$this->scope] => $this->reference->value,
            "events_checked" => $this->events,
            "position" => $this->position,
            "records_checked" => $this->records,
            "issues" => $this->issues,
            "records_changed" => false,
        ];
    }
}
