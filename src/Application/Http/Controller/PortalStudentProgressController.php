<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\StudentProgressService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PortalStudentProgressController
{
    public function __construct(private StudentProgressService $service)
    {
    }
    public function programs(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->programs(new EntityId($student), FormInput::from($request)->optionalId("after")),
        );
    }
    public function review(string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->review(new EntityId($student), new EntityId($assignment)));
    }
}
