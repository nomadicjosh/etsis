<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\BookingCategoryService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisBookingCategoryController
{
    public function __construct(private BookingCategoryService $service)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->create($f->text('code', 80), $f->text('name'), $f->text('tone', 6));
        return new JsonResponse(['id' => $id->value], 201);
    }

    public function edit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->edit(
            new EntityId($id),
            $f->text('code', 80),
            $f->text('name'),
            $f->text('tone', 6),
            $f->requiredBoolean('active'),
            $f->integer('version')
        );
        return new JsonResponse(['status' => 'saved']);
    }
}
