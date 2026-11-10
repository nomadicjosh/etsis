<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\AcademicWorkService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAcademicWorkController
{
    public function __construct(private AcademicWorkService $service)
    {
    }

    public function page(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->page(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
}
