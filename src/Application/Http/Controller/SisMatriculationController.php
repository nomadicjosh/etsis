<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\MatriculationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMatriculationController
{
    public function __construct(private MatriculationService $service)
    {
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->review(new EntityId($id), FormInput::from($request)->bodyText("reason")),
        );
    }
    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 100000),
            ),
            201,
        );
    }
    public function history(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->history(new EntityId($id)));
    }
}
