<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionUpdatesService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionUpdatesController
{
    public function __construct(private AdmissionUpdatesService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        $include = $f->search('include_read', 1);
        if (!in_array($include, ['', '0', '1'], true)) {
            throw new InvalidArgumentException('Choose whether to include read admission updates.');
        }
        return new JsonResponse($this->service->page(
            $include !== '1',
            $f->optionalText('after_at', 32),
            $f->optionalId('after')
        ));
    }
    public function read(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->read(
            new EntityId($id),
            FormInput::from($request)->integer('revision', 1)
        ));
    }
    public function detail(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->detail(new EntityId($id)));
    }
    public function delete(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->delete(
            new EntityId($id),
            FormInput::from($request)->integer('revision', 1)
        ));
    }
}
