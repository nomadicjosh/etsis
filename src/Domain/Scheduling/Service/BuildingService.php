<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Building;
use Domain\Scheduling\Repository\BuildingRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use JsonException;

final readonly class BuildingService
{
    public function __construct(
        private BuildingRepository $buildings,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{id:string,code:string,name:string,revision:string}
     * @throws JsonException
     */
    public function view(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $building = $this->buildings->get($id);
        $this->authorize($actor);
        return ['id' => $id->value, 'code' => $building->code, 'name' => $building->name,
            'revision' => $this->revision($building)];
    }

    public function create(string $code, string $name): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $building = new Building(EntityId::generate(), trim($code), trim($name));
        $this->transactions->run(function () use ($actor, $building): void {
            $this->authorize($actor);
            $this->buildings->add($building);
            $this->audit->record($actor, 'building.created', $building->id);
            $this->authorize($actor);
        });
        return $building->id;
    }

    public function edit(EntityId $id, string $code, string $name, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $building = new Building($id, trim($code), trim($name));
        $this->transactions->run(function () use ($actor, $building, $revision): void {
            $this->buildings->lock($building->id);
            $this->authorize($actor);
            if (!hash_equals($this->revision($this->buildings->get($building->id)), $revision)) {
                throw new ConcurrentModificationException('Reload the building before editing.');
            }
            $this->buildings->save($building);
            $this->audit->record($actor, 'building.updated', $building->id);
            $this->authorize($actor);
        });
    }

    /**
     * @throws JsonException
     */
    private function revision(Building $building): string
    {
        $facts = [$building->id->value, $building->code, $building->name];
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduling session changed.');
        }
    }
}
