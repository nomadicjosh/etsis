<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\StudentReport;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class ReportService
{
    public function __construct(
        private ReportRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    public function create(string $name, ?EntityId $program, ?EntityId $term, ?bool $active): StudentReport
    {
        $actor = $this->access->requirePermission('sis:reporting:manage');
        $report = new StudentReport(EntityId::generate(), $actor, $name, $program, $term, $active);
        $this->transactions->run(function () use ($report, $actor): void {
            $this->repository->add($report);
            $this->audit->record($actor, 'report.created', $report->id);
        });

        return $report;
    }

    public function share(EntityId $id, EntityId $user): void
    {
        $this->changeShare($id, $user, true);
    }

    public function unshare(EntityId $id, EntityId $user): void
    {
        $this->changeShare($id, $user, false);
    }

    private function changeShare(EntityId $id, EntityId $user, bool $grant): void
    {
        $this->transactions->run(function () use ($id, $user, $grant): void {
            $actor = $this->access->requirePermission('sis:reporting:manage');
            $this->repository->lock($id);
            $report = $this->repository->get($id);
            $this->access->requireOwnerOrPermission($report->ownerUserId, 'sis:reporting:admin');
            if ($grant) {
                $this->repository->share($id, $user);
            } else {
                $this->repository->unshare($id, $user);
            }
            $this->audit->record($actor, $grant ? 'report.shared' : 'report.unshared', $id);
            $this->audit->record($actor, $grant ? 'report.share_granted' : 'report.share_revoked', $user);
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function shares(EntityId $id, ?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:reporting:manage');
        $report = $this->repository->get($id);
        $this->access->requireOwnerOrPermission($report->ownerUserId, 'sis:reporting:admin');
        $rows = $this->repository->shares($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $report = $this->repository->get($id);
        $manage = $this->canManage($report);
        $run = true;
        try {
            $this->requireRun($report);
        } catch (AccessDeniedException) {
            $run = false;
        }
        if (!$manage && !$run) {
            throw new AccessDeniedException('Access denied.');
        }

        return $this->repository->describe($id) + ['can_manage' => $manage, 'can_run' => $run];
    }

    private function canManage(StudentReport $report): bool
    {
        try {
            $this->access->requirePermission('sis:reporting:manage');
            $this->access->requireOwnerOrPermission($report->ownerUserId, 'sis:reporting:admin');
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function requireRun(StudentReport $report): EntityId
    {
        $actor = $this->access->requirePermission('sis:reporting:run');
        if ($report->ownerUserId->value !== $actor->value && !$this->repository->isSharedWith($report->id, $actor)) {
            $this->access->requirePermission('sis:reporting:admin');
        }

        return $actor;
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string, limit: int} */
    public function run(EntityId $id, ?EntityId $after = null): array
    {
        return $this->results($id, $after, 'report.executed');
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string, limit: int} */
    public function export(EntityId $id, ?EntityId $after = null): array
    {
        return $this->results($id, $after, 'report.page_exported');
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string, limit: int} */
    private function results(EntityId $id, ?EntityId $after, string $action): array
    {
        $this->access->requirePermission('sis:reporting:run');
        return $this->transactions->run(function () use ($id, $after, $action): array {
            // Sharing changes take this lock too; authorize and read only after acquiring it.
            $this->repository->lock($id);
            $report = $this->repository->get($id);
            $actor = $this->requireRun($report);
            if ($this->repository->isRelational($id)) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'report',
                    'message' => 'Use Report Studio to execute this relational definition.']]);
            }
            $rows = $this->repository->run($report, $after, 51);
            $more = count($rows) > 50;
            $rows = array_slice($rows, 0, 50);
            $this->audit->record($actor, $action, $id);

            return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['student_id'] : null, 'limit' => 50];
        });
    }
}
