<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentActivityService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentActivityController
{
    public function __construct(private StudentActivityService $service)
    {
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->requiredBoolean("active"),
                $f->text("effective_on", 10),
                $f->bodyText("reason"),
                $f->text("revision", 64),
            ),
        );
    }
    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 100000),
            ),
        );
    }
    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 10000),
            ),
        );
    }
}
