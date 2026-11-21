<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\ProfileUpdateRequestService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class ProfileUpdateRequestController
{
    public function __construct(private ProfileUpdateRequestService $service)
    {
    }
    public function mine(ServerRequest $request): ResponseInterface
    {
        return $this->page($request, null, false);
    }
    public function staff(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->page($request, new EntityId($id), true);
    }
    private function page(ServerRequest $request, ?EntityId $person, bool $staff): ResponseInterface
    {
        $form = new FormInput($request->getQueryParams());
        $after = $form->optionalText('after', 26);
        return new JsonResponse($this->service->listing(
            $person,
            $staff,
            $form->boolean('finished'),
            $after === null || $after === '' ? null : new EntityId($after)
        ));
    }
    public function create(ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->service->request(
            new EntityId($id),
            $form->id('request_id'),
            $form->text('area', 20),
            $form->bodyText('message')
        ));
    }
    public function respond(ServerRequest $request, string $requestId): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->service->act(
            null,
            new EntityId($requestId),
            false,
            'respond',
            $form->integer('version'),
            $form->bodyText('message')
        ));
    }
    public function resolve(ServerRequest $request, string $id, string $requestId): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->service->act(
            new EntityId($id),
            new EntityId($requestId),
            true,
            $form->text('action', 12),
            $form->integer('version'),
            $form->bodyText('message')
        ));
    }
}
