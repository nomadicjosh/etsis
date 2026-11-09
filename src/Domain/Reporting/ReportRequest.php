<?php

declare(strict_types=1);

namespace Domain\Reporting;

use DomainException;
use Domain\Shared\ValueObject\EntityId;

final readonly class ReportRequest
{
    public const array STATUSES = ["open", "in_progress", "waiting", "delivered", "cancelled"];
    public function __construct(
        public EntityId $id,
        public EntityId $requestedBy,
        public ?EntityId $assignedTo = null,
        public string $status = "open",
        public int $version = 0,
        public ?EntityId $reportId = null,
    ) {
        if (!in_array($status, self::STATUSES, true) || $version < 0) {
            throw new DomainException("Invalid report request state.");
        }
    }
    public function change(string $action, EntityId $actor, bool $manager, ?EntityId $report = null): self
    {
        $owner = $actor->value === $this->requestedBy->value;
        $assigned = $manager && $actor->value === $this->assignedTo?->value;
        $active = !in_array($this->status, ["delivered", "cancelled"], true);
        [$status, $assignee, $target] = match (true) {
            $action === "claim" && $manager && $active && !$assigned => [
                $this->status === "waiting" ? "waiting" : "in_progress",
                $actor,
                $this->reportId,
            ],
            $action === "clarify" && $assigned && $this->status === "in_progress" => [
                "waiting",
                $this->assignedTo,
                $this->reportId,
            ],
            $action === "reply" && $owner && $this->status === "waiting" => [
                "in_progress",
                $this->assignedTo,
                $this->reportId,
            ],
            $action === "deliver" && $assigned && $this->status === "in_progress" && $report !== null => [
                "delivered",
                $this->assignedTo,
                $report,
            ],
            $action === "cancel" && $active && ($owner || $assigned) => [
                "cancelled",
                $this->assignedTo,
                $this->reportId,
            ],
            $action === "reopen" && !$active && ($owner || $assigned) => ["open", null, $this->reportId],
            $action === "note" && ($owner || $manager) => [$this->status, $this->assignedTo, $this->reportId],
            default => throw new DomainException(
                "Choose an available action; claim the request before working on it.",
            ),
        };
        return new self($this->id, $this->requestedBy, $assignee, $status, $this->version + 1, $target);
    }
}
