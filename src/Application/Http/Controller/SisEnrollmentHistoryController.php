<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\EnrollmentHistoryService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisEnrollmentHistoryController
{
    public function __construct(private EnrollmentHistoryService $service)
    {
    }
    public function student(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->student(new EntityId($student), FormInput::from($request)->optionalId("after")),
        );
    }
}
