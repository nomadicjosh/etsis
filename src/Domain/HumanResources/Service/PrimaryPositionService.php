<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Employee;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\HumanResources\ValueObject\PrimaryPosition;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

final readonly class PrimaryPositionService
{
    public function __construct(
        private TimesheetService $employment,
        private PrimaryPositionLedger $ledger,
        private PositionLedger $positions,
        private EmploymentDatesLedger $dates,
        private StudentAccess $access,
        private ReviewProof $proof,
        private AuditTrail $audit,
        private ActorDirectory $actors,
        private Clock $clock,
    ) {
    }

    /** Owner/HR summary excludes reasons, recorder identities and compensation.
     * @return array<string,mixed>
     */
    public function get(EntityId $employee): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employment->employeeProfile($employee);
        $summary = $this->ledger->summary($this->ledger->inspect($employee)["root"]->designation());
        $this->employment->recheckEmployee($profile->employee, $actor);
        return $summary + ["operation_id" => EntityId::generate()->value];
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $employee,
        EntityId $operation,
        int $version,
        ?EntityId $position,
        ?int $positionVersion,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->requireAuthority();
        $profile = $this->employment->employeeProfile($employee);
        $before = $this->ledger->inspect($employee)["root"]->designation();
        $selection = $this->selection($employee, $position, $positionVersion);
        $this->requireChange($before, $version, $selection);
        $intent = $this->intent($version, $position, $positionVersion, $reason);
        $this->employment->recheckEmployee($profile->employee, $actor);
        $this->requireAuthority($actor);
        return [
            "before" => $before->selection,
            "after" => $selection,
            "review_token" => $this->proof->issue(
                "hr.primary-position",
                "confirm",
                $employee,
                $actor,
                $operation,
                $intent,
            ),
        ];
    }

    public function confirm(
        EntityId $employee,
        EntityId $operation,
        int $version,
        ?EntityId $position,
        ?int $positionVersion,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): void {
        $actor = $this->requireAuthority();
        $reason = DefinitionRules::reason($reason);
        $intent = $this->intent($version, $position, $positionVersion, $reason);
        $this->employment->maintainEmployee($employee, function (
            Employee $parent,
            EntityId $lockedActor,
        ) use (
            $actor,
            $operation,
            $version,
            $position,
            $positionVersion,
            $reason,
            $token,
            $intent,
        ): void {
            $this->requireAuthority($actor);
            if ($lockedActor->value !== $actor->value) {
                throw new AccessDeniedException();
            }
            $loaded = $this->ledger->inspect($parent->id, $operation, $actor, $intent);
            $this->proof->validate(
                $token,
                "hr.primary-position",
                "confirm",
                $parent->id,
                $actor,
                $operation,
                $intent,
                accepted: $loaded["receipt"],
            );
            if ($loaded["receipt"]) {
                return;
            }
            $root = $loaded["root"];
            $selection = $this->selection($parent->id, $position, $positionVersion);
            $this->requireChange($root->designation(), $version, $selection);
            $today = $this->clock->now()->format("Y-m-d");
            $this->ledger->record(
                $root,
                new PrimaryPosition($parent->id, $selection, $version + 1),
                $operation,
                $actor,
                $intent,
                $reason,
            );
            $this->audit->record($actor, "employee.primary_position.recorded", $parent->id);
            $this->requireAuthority($actor);
            $this->proof->validate(
                $token,
                "hr.primary-position",
                "confirm",
                $parent->id,
                $actor,
                $operation,
                $intent,
            );
            if (
                $today !== $this->clock->now()->format("Y-m-d") ||
                $selection !== $this->selection($parent->id, $position, $positionVersion) ||
                $this->ledger->inspect($parent->id)["root"]->designation()->facts() !==
                    $root->designation()->facts()
            ) {
                throw new ConcurrentModificationException("Review the primary position again.");
            }
        });
    }

    /** Curated fixed-size pages. Reasons and recorder labels require both HR and audit duties.
     * @return array<string,mixed>
     */
    public function history(EntityId $employee, int $after = 0, ?int $through = null): array
    {
        $actor = $this->access->requirePermission("sis:hr:manage");
        $this->access->requirePermission("sis:audit:read");
        $profile = $this->employment->employeeProfile($employee);
        $loaded = $this->ledger->inspect($employee);
        $through ??= $loaded["root"]->playhead();
        if ($after < 0 || $through < $after || $through > $loaded["root"]->playhead()) {
            DefinitionRules::invalid("after", "Reload primary-position history.");
        }
        $items = array_slice($loaded["items"], $after, min(20, $through - $after));
        $ids = [];
        foreach ($items as $item) {
            $ids[$item["actor_id"]] = new EntityId($item["actor_id"]);
        }
        $names = $this->actors->names(array_values($ids));
        foreach ($items as &$item) {
            $item["recorded_by"] = $names[$item["actor_id"]] ?? "Unavailable staff member";
            unset($item["actor_id"]);
        }
        unset($item);
        $this->employment->recheckEmployee($profile->employee, $actor);
        if (
            $this->access->requirePermission("sis:hr:manage")->value !== $actor->value ||
            $this->access->requirePermission("sis:audit:read")->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return [
            "items" => $items,
            "through_version" => $through,
            "next_after" => $after + count($items) < $through ? $after + count($items) : null,
        ];
    }

    /** @return ?array<string,mixed> */
    private function selection(EntityId $employee, ?EntityId $id, ?int $version): ?array
    {
        if (($id === null) !== ($version === null)) {
            DefinitionRules::invalid(
                "position_id",
                "Review the position and its version together.",
            );
        }
        if ($id === null) {
            return null;
        }
        $position = $this->positions->verified($employee, $id);
        if ($position->version !== $version) {
            throw new ConcurrentModificationException("The appointment changed. Review it again.");
        }
        $today = $this->clock->now()->format("Y-m-d");
        if (
            $position->voided ||
            $position->from > $today ||
            ($position->through !== null && $position->through < $today)
        ) {
            DefinitionRules::invalid("position_id", "Choose a valid appointment covering today.");
        }
        $this->dates->requireCoverage($employee, $today, $today, "position_id");
        return PrimaryPosition::selection($position);
    }

    /** @param ?array<string,mixed> $selection */
    private function requireChange(PrimaryPosition $before, int $version, ?array $selection): void
    {
        if ($before->version !== $version) {
            throw new ConcurrentModificationException(
                "The primary designation changed. Review it again.",
            );
        }
        if ($version >= 2000) {
            throw new HistoryReadLimitException();
        }
        if ($before->selection === $selection) {
            DefinitionRules::invalid(
                "position_id",
                "Choose a different position or review a changed appointment.",
            );
        }
    }
    private function intent(
        int $version,
        ?EntityId $position,
        ?int $positionVersion,
        string $reason,
    ): string {
        return EmploymentLedger::intent([
            $version,
            $position?->value,
            $positionVersion,
            DefinitionRules::reason($reason),
        ]);
    }
    private function requireAuthority(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:hr:manage");
        if (
            $this->access->requirePermission("sis:hr:primary")->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
}
