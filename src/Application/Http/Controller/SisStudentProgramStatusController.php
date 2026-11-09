<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramStatusService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentProgramStatusController
{
    public function __construct(private StudentProgramStatusService $service)
    {
    }
    public function review(ServerRequest $request, string $id, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                new EntityId($assignment),
                $f->requiredBoolean("active"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commit(ServerRequest $request, string $id, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                new EntityId($assignment),
                FormInput::from($request)->text("review_token", 100000),
            ),
        );
    }
    public function history(ServerRequest $request, string $id, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($id),
                new EntityId($assignment),
                FormInput::from($request)->optionalText("cursor", 10000),
            ),
        );
    }
}
