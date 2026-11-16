<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Office\Service\OfficeService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisOfficeController
{
    public function __construct(private OfficeService $service)
    {
    }

    public function choices(): ResponseInterface
    {
        return new JsonResponse($this->service->choices());
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->save(
            new EntityId($id),
            $f->text('code', 22),
            $f->text('name', 120),
            $f->requiredBoolean('active'),
            $f->integer('revision', -1, 1000000000)
        );
        return new JsonResponse(['status' => 'saved']);
    }

    public function delegates(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->delegates(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function delegate(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->delegate(
            new EntityId($id),
            $f->id('user_id'),
            $f->date('starts_on'),
            $f->requiredBoolean('active'),
            $f->integer('revision', -1, 1000000000),
            $f->optionalDate('ends_on')
        );
        return new JsonResponse(['status' => 'saved']);
    }

    public function people(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->people(FormInput::from($request)->search()));
    }

    public function accounts(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->people(FormInput::from($request)->search(), true));
    }
}
