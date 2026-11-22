<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Domain\HumanResources\EmployeePosition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Retained designation, distinct from the appointment's compensation or current eligibility. */
final readonly class PrimaryPosition
{
    /** @var ?array<string,mixed> */
    public ?array $selection;

    /** @param ?array<string,mixed> $selection */
    public function __construct(public EntityId $employee, ?array $selection, public int $version)
    {
        if ($version < 0 || $version > 2000 || ($version === 0 && $selection !== null)) {
            throw new CorruptHistoryException();
        }
        if ($selection === null) {
            $this->selection = null;
            return;
        }
        if (
            count($selection) !== 7 ||
            array_diff(array_keys($selection), [
                "id",
                "version",
                "job_id",
                "code",
                "title",
                "from",
                "through",
            ]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($selection["id"]);
        new EntityId($selection["job_id"]);
        if (
            !is_int($selection["version"]) ||
            $selection["version"] < 1 ||
            $selection["version"] > 2000 ||
            DefinitionRules::code($selection["code"]) !== $selection["code"] ||
            DefinitionRules::title($selection["title"]) !== $selection["title"]
        ) {
            throw new CorruptHistoryException();
        }
        EmployeePosition::date($selection["from"], "from");
        if ($selection["through"] !== null) {
            EmployeePosition::date($selection["through"], "through");
            if ($selection["through"] < $selection["from"]) {
                throw new CorruptHistoryException();
            }
        }
        $this->selection = array_replace(
            array_fill_keys(["id", "version", "job_id", "code", "title", "from", "through"], null),
            $selection,
        );
    }

    /** @return array<string,mixed> */
    public static function selection(EmployeePosition $position): array
    {
        return [
            "id" => $position->id->value,
            "version" => $position->version,
            "job_id" => $position->job["id"],
            "code" => $position->job["code"],
            "title" => $position->job["title"],
            "from" => $position->from,
            "through" => $position->through,
        ];
    }

    /** @return array<string,mixed> */
    public function facts(): array
    {
        return [
            "employee_id" => $this->employee->value,
            "selection" => $this->selection,
            "version" => $this->version,
        ];
    }

    /** @param array<string,mixed> $facts */
    public static function restore(array $facts): self
    {
        if (
            count($facts) !== 3 ||
            array_diff(array_keys($facts), ["employee_id", "selection", "version"]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        return new self(
            new EntityId($facts["employee_id"]),
            $facts["selection"],
            $facts["version"],
        );
    }
}
