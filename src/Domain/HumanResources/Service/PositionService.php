<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\ActorDirectory;
use Domain\HumanResources\Employee;
use Domain\HumanResources\EmployeePosition;
use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\Repository\HrLibraryRepository;
use Domain\HumanResources\Repository\PositionRepository;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

final readonly class PositionService
{
    public const int MAX_EVENTS = PositionLedger::MAX_EVENTS;
    public function __construct(
        private TimesheetService $employment,
        private PositionRepository $rows,
        private HrLibraryRepository $definitions,
        private StudentAccess $access,
        private Clock $clock,
        private ActorDirectory $actors,
        private EmploymentDatesLedger $employmentDates,
        private PositionLedger $ledger,
    ) {
    }
    /** @return array<string,mixed> */
    public function page(EntityId $employee, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $profile = $this->employment->employeeProfile($employee);
        $rows = $this->rows->page($employee, $after);
        foreach ($rows as $position) {
            $this->owns($position, $employee);
        }
        $this->finishRead($profile->employee, $actor);
        return ['items' => array_map($this->summary(...), array_slice($rows, 0, 20)),
            'next_after' => count($rows) > 20 ? $rows[19]->id->value : null,
            'new_id' => EntityId::generate()->value, 'today' => $this->clock->now()->format('Y-m-d')];
    }
    /** @return array<string,mixed> */
    public function get(EntityId $employee, EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $profile = $this->employment->employeeProfile($employee);
        $row = $this->rows->find($id);
        $loaded = $this->ledger->load($id);
        if ($row === null && $loaded['root']->playhead() === 0) {
            throw new RecordNotFoundException('Position assignment not found.');
        }
        $this->owns($row ?? throw new CorruptHistoryException(), $employee);
        $this->ledger->verify($loaded['root'], $row);
        $last = $loaded['last'] ?? throw new CorruptHistoryException();
        $recorder = $last->context->actor ?? throw new CorruptHistoryException();
        $names = $this->actors->names([$recorder]);
        $this->finishRead($profile->employee, $actor);
        return $row->facts() + ['recorded_at' => $last->recordedAt->format(DATE_ATOM),
            'recorded_by' => $names[$recorder->value] ?? 'Unavailable account'];
    }
    public function assign(
        EntityId $employee,
        EntityId $id,
        EntityId $job,
        int $jobVersion,
        ?int $gradeVersion,
        string $from,
        ?string $through,
        ?int $rateMinor,
        ?int $weeklyMinutes,
        #[\SensitiveParameter] string $reason,
        ?EntityId $replaces = null,
        ?int $replacedVersion = null,
    ): EntityId {
        $this->access->requirePermission('sis:hr:manage');
        $reason = DefinitionRules::reason($reason);
        EmployeePosition::date($from, 'valid_from');
        if ($through !== null) {
            EmployeePosition::date($through, 'valid_through');
        }
        $intent = EmploymentLedger::intent(['assign', $employee->value, $job->value, $jobVersion, $gradeVersion,
            $from, $through, $rateMinor, $weeklyMinutes, $reason, $replaces?->value, $replacedVersion]);
        return $this->employment->maintainEmployee($employee, function (
            Employee $parent,
            EntityId $actor
        ) use (
            $id,
            $job,
            $jobVersion,
            $gradeVersion,
            $from,
            $through,
            $rateMinor,
            $weeklyMinutes,
            $reason,
            $replaces,
            $replacedVersion,
            $intent
        ): EntityId {
            $operation = $this->operation($id, $actor, $intent);
            $loaded = $this->ledger->load($id, $operation, $actor, $intent);
            $existing = $this->rows->find($id);
            if ($loaded['receipt']) {
                $this->ledger->verify($loaded['root'], $existing);
                $this->owns($existing ?? throw new CorruptHistoryException(), $parent->id);
                return $id;
            }
            if ($existing !== null || $loaded['root']->playhead() !== 0) {
                throw new ConcurrentModificationException('That assignment identity was already used.');
            }
            $this->definitions->lock(HrLibraryKind::Jobs, $job);
            $source = $this->definitions->find(HrLibraryKind::Jobs, $job)
                ?? throw new RecordNotFoundException('Job definition not found.');
            $definition = $source['value'];
            if (!$definition instanceof JobDefinition || !$definition->active || $source['version'] !== $jobVersion) {
                DefinitionRules::invalid('job_id', 'Review an active, current job definition before assigning it.');
            }
            $grade = null;
            if ($definition->payGradeId !== null) {
                $this->definitions->lock(HrLibraryKind::PayGrades, $definition->payGradeId);
                $g = $this->definitions->find(HrLibraryKind::PayGrades, $definition->payGradeId)
                    ?? throw new RecordNotFoundException('Pay grade not found.');
                if (!$g['value'] instanceof PayGrade || $g['version'] !== $gradeVersion) {
                    DefinitionRules::invalid('job_id', 'The pay grade changed. Review the job defaults again.');
                }
                $grade = $g['value'];
                $definition->validateGrade($grade);
            } elseif ($gradeVersion !== null) {
                DefinitionRules::invalid('job_id', 'This job has no pay grade. Review its defaults again.');
            }
            $position = new EmployeePosition(
                $id,
                $parent->id,
                $definition->fields(),
                $jobVersion,
                $grade?->fields(),
                $gradeVersion,
                $from,
                $through,
                $rateMinor,
                $weeklyMinutes,
                1,
                DefinitionRules::precision($definition->currency),
                replaces: $replaces
            );
            $this->employmentDates->requireCoverage($parent->id, $from, $through, 'valid_from');
            if (($replaces === null) !== ($replacedVersion === null)) {
                DefinitionRules::invalid('replaces_id', 'Review the assignment being replaced.');
            }
            if ($replaces !== null) {
                $this->rows->lock($replaces);
                $old = $this->rows->find($replaces)
                    ?? throw new RecordNotFoundException('Previous assignment not found.');
                $this->owns($old, $parent->id);
                $oldRoot = $this->ledger->load($replaces)['root'];
                $this->ledger->verify($oldRoot, $old);
                $end = new DateTimeImmutable($from, $this->clock->now()->getTimezone())
                    ->modify('-1 day')->format('Y-m-d');
                if (
                    $old->version !== $replacedVersion || $old->voided || $from <= $old->from
                    || ($old->through !== null && $old->through < $end)
                ) {
                    DefinitionRules::invalid(
                        'valid_from',
                        'Choose a change date within the reviewed previous assignment.'
                    );
                }
                if ($old->through !== $end) {
                    if ($old->version >= self::MAX_EVENTS) {
                        throw new HistoryReadLimitException();
                    }
                    $closed = $old->changeEnd($end);
                    $this->ledger->record(
                        $oldRoot,
                        $closed,
                        DerivedIdentity::from($operation, 'close-previous'),
                        $actor,
                        $intent,
                        'end_changed',
                        $reason
                    );
                }
            }
            $this->ledger->record($loaded['root'], $position, $operation, $actor, $intent, 'assigned', $reason);
            return $id;
        });
    }
    /** A null through date explicitly removes a scheduled closure. */
    public function end(
        EntityId $employee,
        EntityId $id,
        int $version,
        ?string $through,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->change($employee, $id, $version, $through, null, $reason);
    }
    public function setVoid(
        EntityId $employee,
        EntityId $id,
        int $version,
        bool $voided,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->change($employee, $id, $version, null, $voided, $reason);
    }

    /** Internal proposal for the HR dates workflow; current compensation values are not returned.
     * @return array{revision:string,changes:list<array{id:string,code:string,title:string,action:string,through:?string}>}
     */
    public function employmentImpact(
        EntityId $employee,
        EmploymentDates $before,
        EmploymentDates $after
    ): array {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $profile = $this->employment->employeeProfile($employee);
        $plan = $this->dateImpact($employee, $before, $after);
        $this->finishRead($profile->employee, $actor);
        return $plan;
    }

    /** Called inside the dates service's employee transaction; joined writes cannot partially commit. */
    public function applyEmploymentDates(
        EntityId $employee,
        EmploymentDates $before,
        EmploymentDates $after,
        string $reviewedRevision,
        EntityId $operation,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->employment->maintainEmployee($employee, function (
            Employee $parent,
            EntityId $actor
        ) use (
            $before,
            $after,
            $reviewedRevision,
            $operation,
            $reason
        ): void {
            $plan = $this->dateImpact($parent->id, $before, $after);
            if (!hash_equals($plan['revision'], $reviewedRevision)) {
                throw new ConcurrentModificationException('Positions changed. Review the employment dates again.');
            }
            foreach ($plan['changes'] as $change) {
                $id = new EntityId($change['id']);
                $this->rows->lock($id);
                $current = $this->rows->find($id) ?? throw new CorruptHistoryException();
                $this->owns($current, $parent->id);
                $root = $this->ledger->load($id)['root'];
                $this->ledger->verify($root, $current);
                if ($root->playhead() >= self::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $next = $change['action'] === 'Close assignment' ? $current->changeEnd($change['through'])
                    : $current->changeVoid(true);
                $intent = EmploymentLedger::intent(['employment-dates', $operation->value, $next->facts()]);
                $this->ledger->record(
                    $root,
                    $next,
                    DerivedIdentity::from($operation, 'position:' . $id->value),
                    $actor,
                    $intent,
                    $change['action'] === 'Close assignment' ? 'end_changed' : 'void_changed',
                    $reason
                );
            }
        });
    }

    /** @return array{revision:string,changes:list<array{id:string,code:string,title:string,action:string,through:?string}>} */
    private function dateImpact(
        EntityId $employee,
        EmploymentDates $before,
        EmploymentDates $after
    ): array {
        if ($employee->value !== $before->employee->value || $employee->value !== $after->employee->value) {
            throw new AccessDeniedException('Another employment timeline.');
        }
        $cursor = null;
        $seen = [];
        $changes = [];
        $versions = [];
        $events = 0;
        $starts = [...array_column($before->periods, 'from'), ...array_column($after->periods, 'from')];
        $first = $starts === [] ? null : min($starts);
        do {
            $page = $this->rows->page($employee, $cursor);
            foreach (array_slice($page, 0, 20) as $row) {
                $this->owns($row, $employee);
                if (isset($seen[$row->id->value]) || count($seen) >= 500) {
                    throw new HistoryReadLimitException();
                }
                $seen[$row->id->value] = true;
                $versions[] = [$row->id->value, $row->version];
                // Earlier, already-ended assignments remain unknown rather than becoming new HR assertions.
                if (
                    $row->voided || $after->covers($row->from, $row->through)
                    || ($row->through !== null && $first !== null && $row->through < $first)
                ) {
                    continue;
                }
                $period = $after->at($row->from);
                $changes[] = ['id' => $row->id->value, 'code' => $row->job['code'], 'title' => $row->job['title'],
                    'action' => $period === null ? 'Void assignment' : 'Close assignment',
                    'through' => $period['through'] ?? null];
                $events += $row->version;
                if (count($changes) > 50 || $events > 5000) {
                    throw new HistoryReadLimitException();
                }
            }
            $cursor = count($page) > 20 ? $page[19]->id : null;
        } while ($cursor !== null);
        return ['revision' => EmploymentLedger::intent($versions), 'changes' => $changes];
    }
    private function change(
        EntityId $employee,
        EntityId $id,
        int $version,
        ?string $through,
        ?bool $voided,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->access->requirePermission('sis:hr:manage');
        $reason = DefinitionRules::reason($reason);
        $action = $voided === null ? 'end_changed' : 'void_changed';
        $intent = EmploymentLedger::intent([
            $action, $employee->value, $id->value, $version, $through, $voided, $reason,
        ]);
        $this->employment->maintainEmployee($employee, function (
            Employee $parent,
            EntityId $actor
        ) use (
            $id,
            $version,
            $through,
            $voided,
            $reason,
            $intent,
            $action
        ): void {
            $this->rows->lock($id);
            $current = $this->rows->find($id) ?? throw new RecordNotFoundException('Position assignment not found.');
            $this->owns($current, $parent->id);
            $operation = $this->operation($id, $actor, $intent);
            $loaded = $this->ledger->load($id, $operation, $actor, $intent);
            $this->ledger->verify($loaded['root'], $current);
            if ($loaded['receipt']) {
                return;
            }
            if ($version !== $current->version) {
                throw new ConcurrentModificationException('Reload the assignment.');
            }
            if ($voided === null && $current->voided) {
                DefinitionRules::invalid('valid_through', 'Restore a voided assignment before changing its end date.');
            }
            if (($voided === null && $current->through === $through) || $current->voided === $voided) {
                return;
            }
            if ($current->version >= self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            $next = $voided === null ? $current->changeEnd($through) : $current->changeVoid($voided);
            if (!$next->voided) {
                $this->employmentDates->requireCoverage($parent->id, $next->from, $next->through, 'valid_through');
            }
            $this->ledger->record($loaded['root'], $next, $operation, $actor, $intent, $action, $reason);
        });
    }
    private function owns(EmployeePosition $position, EntityId $employee): void
    {
        if ($position->employeeId->value !== $employee->value) {
            throw new AccessDeniedException('Another employee position.');
        }
    }
    private function finishRead(Employee $employee, EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:hr:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The position reader changed.');
        }
        $this->employment->recheckEmployee($employee, $actor);
    }
    private function operation(EntityId $id, EntityId $actor, string $intent): EntityId
    {
        return DerivedIdentity::from($id, 'employment.position:' . $actor->value . ':' . $intent);
    }
    /** @return array<string,mixed> */
    private function summary(EmployeePosition $p): array
    {
        return ['id' => $p->id->value, 'job_code' => $p->job['code'], 'title' => $p->job['title'],
            'currency' => $p->job['currency'], 'basis' => $p->job['basis'], 'currency_digits' => $p->currencyDigits,
            'valid_from' => $p->from, 'valid_through' => $p->through, 'rate_minor' => $p->rateMinor,
            'weekly_minutes' => $p->weeklyMinutes, 'version' => $p->version, 'voided' => $p->voided];
    }
}
