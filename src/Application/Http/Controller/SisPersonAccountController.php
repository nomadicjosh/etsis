<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\PersonAccountService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPersonAccountController
{
    public function __construct(private PersonAccountService $service)
    {
    }

    public function enable(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        $user = $this->service->enable(
            new EntityId($id),
            $input->text('username', 20),
            $input->text('email'),
            $input->password()
        );

        return new JsonResponse(['id' => $user->value], 201);
    }
}
