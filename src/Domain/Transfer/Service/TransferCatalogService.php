<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCatalogRepository;
use Domain\Transfer\TransferEquivalency;
use Domain\Transfer\ValueObject\TransferLabel;
use Domain\Transfer\ValueObject\TransferRevision;
use InvalidArgumentException;

final readonly class TransferCatalogService
{
    public function __construct(
        private TransferCatalogRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(string $kind, EntityId $id): array
    {
        $this->access->requirePermission('sis:transfer:manage');
        $record = $this->repository->get($kind, $id);

        return $record + ['revision' => TransferRevision::of($kind, $record)];
    }

    public function editLabel(string $kind, EntityId $id, string $code, string $title, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:transfer:manage');
        if (!in_array($kind, ['institutions', 'external'], true)) {
            throw new InvalidArgumentException('Unsupported transfer label.');
        }
        $label = new TransferLabel($code, $title, $kind === 'institutions' ? 'name' : 'title');
        $initial = $this->repository->get($kind, $id);
        $this->transactions->run(function () use ($kind, $id, $label, $initial, $revision, $actor): void {
            TransferCatalogLock::acquire($this->repository, $kind, $initial);
            $current = $this->repository->get($kind, $id);
            $this->check($kind, $current, $revision);
            $this->repository->save($kind, $id, ['code' => $label->code,
                $kind === 'institutions' ? 'name' : 'title' => $label->title]);
            $this->audit->record(
                $actor,
                $kind === 'institutions' ? 'transfer.institution_updated' : 'transfer.course_updated',
                $id
            );
        });
    }

    public function editEquivalency(
        EntityId $id,
        DateRange $dates,
        int $rank,
        int $credits,
        string $grade,
        string $revision
    ): void {
        $actor = $this->access->requirePermission('sis:transfer:manage');
        $initial = $this->repository->get('equivalencies', $id);
        $this->transactions->run(function () use (
            $id,
            $dates,
            $rank,
            $credits,
            $grade,
            $revision,
            $initial,
            $actor
        ): void {
            TransferCatalogLock::acquire($this->repository, 'equivalencies', $initial);
            $current = $this->repository->get('equivalencies', $id);
            $this->check('equivalencies', $current, $revision);
            $equivalency = new TransferEquivalency(
                $id,
                new EntityId($current['institution_id']),
                new EntityId($current['external_course_id']),
                new EntityId($current['course_id']),
                $dates,
                $rank,
                $credits,
                $grade
            );
            $gradeId = $this->repository->passingGrade($equivalency->awardedGrade);
            $this->repository->save('equivalencies', $id, ['starts_on' => $dates->start->format('Y-m-d'),
                'ends_on' => $dates->end?->format('Y-m-d'), 'minimum_grade_rank' => $rank,
                'credits_milli' => $credits, 'grade_id' => $gradeId->value]);
            $this->audit->record($actor, 'transfer.equivalency_updated', $id);
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function children(string $kind, EntityId $id, ?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:transfer:manage');
        $this->repository->get($kind, $id);

        return $this->page($this->repository->children($kind, $id, $after, 51));
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function awards(EntityId $id, ?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:transfer:manage');
        $this->repository->get('equivalencies', $id);

        return $this->page($this->repository->awards($id, $after, 51));
    }

    /** @param array<string, mixed> $record */
    private function check(string $kind, array $record, string $revision): void
    {
        if (!hash_equals(TransferRevision::of($kind, $record), $revision)) {
            throw new ConcurrentModificationException('Reload the transfer catalog record before saving.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{items: list<array<string, mixed>>, next_after: ?string}
     */
    private function page(array $rows): array
    {
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }
}
