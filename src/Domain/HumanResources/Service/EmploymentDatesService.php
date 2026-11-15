<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Employee;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

final readonly class EmploymentDatesService
{
    public function __construct(
        private TimesheetService $employment,
        private EmploymentDatesLedger $ledger,
        private PositionService $positions,
        private StudentAccess $access,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    /** Owner-readable dates, without reasons, compensation or raw events.
     * @return array<string,mixed>
     */
    public function get(EntityId $employee): array
    {
        $actor = $this->access->authenticatedUserId();
        $profile = $this->employment->employeeProfile($employee);
        $dates = $this->ledger->inspect($employee)['root']->dates();
        $this->employment->recheckEmployee($profile->employee, $actor);
        return $dates->facts() + ['new_id' => EntityId::generate()->value,
            'earlier_dates_unknown' => true, 'today' => $this->clock->now()->format('Y-m-d')];
    }
    /** @return array<string,mixed> */
    public function review(
        EntityId $employee,
        EntityId $period,
        int $version,
        string $from,
        ?string $through,
        bool $voided,
        #[\SensitiveParameter] string $reason
    ): array {
        $actor = $this->access->requirePermission('sis:hr:manage');
        DefinitionRules::reason($reason);
        $profile = $this->employment->employeeProfile($employee);
        $root = $this->ledger->inspect($employee)['root'];
        if ($root->playhead() !== $version) {
            throw new ConcurrentModificationException('Review current employment dates.');
        }
        if ($version >= 2000) {
            throw new HistoryReadLimitException();
        }
        $next = $root->dates()->change($period, $from, $through, $voided);
        $impact = $this->positions->employmentImpact($employee, $root->dates(), $next);
        if ($this->access->requirePermission('sis:hr:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The HR reviewer changed.');
        }
        $this->employment->recheckEmployee($profile->employee, $actor);
        return ['positions' => $impact['changes'], 'position_revision' => $impact['revision']];
    }
    public function confirm(
        EntityId $employee,
        EntityId $period,
        int $version,
        string $from,
        ?string $through,
        bool $voided,
        #[\SensitiveParameter] string $reason,
        string $positionRevision
    ): void {
        $reason = DefinitionRules::reason($reason);
        $intent = EmploymentLedger::intent([
            $period->value, $version, $from, $through, $voided, $reason, $positionRevision,
        ]);
        $this->employment->maintainEmployee($employee, function (
            Employee $parent,
            EntityId $actor
        ) use (
            $period,
            $version,
            $from,
            $through,
            $voided,
            $reason,
            $positionRevision,
            $intent
        ): void {
            $operation = DerivedIdentity::from($parent->id, 'employment.dates:' . $actor->value . ':' . $intent);
            $loaded = $this->ledger->inspect($parent->id, $operation, $actor, $intent);
            if ($loaded['receipt']) {
                return;
            }
            $root = $loaded['root'];
            if ($root->playhead() !== $version) {
                throw new ConcurrentModificationException('Review current employment dates.');
            }
            if ($version >= 2000) {
                throw new HistoryReadLimitException();
            }
            $next = $root->dates()->change($period, $from, $through, $voided);
            if ($root->dates()->periods === $next->periods) {
                return;
            }
            $this->positions->applyEmploymentDates(
                $parent->id,
                $root->dates(),
                $next,
                $positionRevision,
                $operation,
                $reason
            );
            $this->ledger->record($root, $next, $period, $operation, $actor, $intent, $reason);
            $this->audit->record($actor, 'employee.dates.recorded', $parent->id);
        });
    }
}
