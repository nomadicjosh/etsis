<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\RoomService;
use Domain\Scheduling\Service\BuildingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRoomController
{
    public function __construct(private RoomService $service, private BuildingService $buildings)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->create(
            $f->text('code', 80),
            $f->text('name'),
            $f->id('building_id'),
            $f->integer('capacity', 1, 100000)
        );
        return new JsonResponse(['id' => $id->value], 201);
    }

    public function building(string $id): ResponseInterface
    {
        return new JsonResponse($this->buildings->view(new EntityId($id)));
    }

    public function createBuilding(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->buildings->create($f->text('code', 80), $f->text('name'));
        return new JsonResponse(['id' => $id->value], 201);
    }

    public function editBuilding(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->buildings->edit(new EntityId($id), $f->text('code', 80), $f->text('name'), $f->text('revision', 64));
        return new JsonResponse(['status' => 'saved']);
    }

    public function edit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->edit(
            new EntityId($id),
            $f->text('code', 80),
            $f->text('name'),
            $f->id('building_id'),
            $f->integer('capacity', 1, 100000),
            $f->text('revision', 64)
        );

        return new JsonResponse(['status' => 'saved']);
    }

    public function availability(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->availability(
            new EntityId($id),
            new TimeSlot($f->instant('starts_at'), $f->instant('ends_at'))
        ));
    }

    public function agenda(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->agenda(
            new EntityId($id),
            new TimeSlot($f->instant('starts_at'), $f->instant('ends_at')),
            $f->optionalId('after')
        ));
    }

    public function week(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);

        return new JsonResponse($this->service->week(new EntityId($id), $f->optionalText('week', 10)));
    }
}
