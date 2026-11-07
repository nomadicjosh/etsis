<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\StudentReport;
use Domain\Shared\Persistence\UnitOfWork;
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

    /** @return list<array<string, mixed>> */
    public function run(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:reporting:run');
        $report = $this->repository->get($id);
        if ($report->ownerUserId->value !== $actor->value && !$this->repository->isSharedWith($id, $actor)) {
            $this->access->requirePermission('sis:reporting:admin');
        }
        $this->audit->record($actor, 'report.executed', $id);

        return $this->repository->run($report);
    }
}
