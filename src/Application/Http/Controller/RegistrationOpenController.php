<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\RegistrationOpenService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class RegistrationOpenController
{
    public function __construct(private RegistrationOpenService $service)
    {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $input = new FormInput($request->getQueryParams());
        $include = $input->search('include_read', 1);
        if (!in_array($include, ['', '0', '1'], true)) {
            throw new InvalidArgumentException(trans('Choose whether to include read registration notices.'));
        }
        return new JsonResponse($this->service->page(
            $include !== '1',
            $input->optionalText('after_at', 27),
            $input->optionalId('after')
        ));
    }

    public function read(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->read(
            new EntityId($id),
            FormInput::from($request)->integer('revision', 1, 1000)
        ));
    }

    public function delete(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->delete(
            new EntityId($id),
            FormInput::from($request)->integer('revision', 1, 1000)
        ));
    }
}
