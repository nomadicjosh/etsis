<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\RoomService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRoomController
{
    public function __construct(private RoomService $service)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function edit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->edit(
            new EntityId($id),
            $f->text('code', 80),
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
}
