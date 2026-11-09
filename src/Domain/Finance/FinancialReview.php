<?php

declare(strict_types=1);

namespace Domain\Finance;

use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class FinancialReview
{
    public function __construct(
        public EntityId $id,
        public EntityId $student,
        public EntityId $hiatus,
        public EntityId $requestedBy,
        public string $createdAt,
        public string $status = "open",
        public ?EntityId $assignedTo = null,
        public int $version = 0,
    ) {
        if (!in_array($status, ["open", "in_review", "completed", "cancelled"], true) || $version < 0) {
            throw new DomainException("Invalid finance review state.");
        }
    }

    public function change(string $action, EntityId $actor): self
    {
        [$status, $assigned] = match (true) {
            $action === "claim" && in_array($this->status, ["open", "in_review"], true) => ["in_review", $actor],
            $action === "complete" && $this->status === "in_review" && $this->assignedTo?->value === $actor->value => [
                "completed",
                $actor,
            ],
            $action === "cancel" && in_array($this->status, ["open", "in_review"], true) => [
                "cancelled",
                $this->assignedTo,
            ],
            $action === "reopen" && in_array($this->status, ["completed", "cancelled"], true) => ["open", null],
            default => throw new DomainException(
                "Claim an open review before completing it, or choose a valid transition.",
            ),
        };
        return new self(
            $this->id,
            $this->student,
            $this->hiatus,
            $this->requestedBy,
            $this->createdAt,
            $status,
            $assigned,
            $this->version + 1,
        );
    }
}
