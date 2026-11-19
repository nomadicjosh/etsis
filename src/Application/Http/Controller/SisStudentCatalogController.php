<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentCatalogService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentCatalogController
{
    public function __construct(private StudentCatalogService $service)
    {
    }
    public function show(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($assignment)));
    }
    public function choices(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->choices(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function review(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("catalog_id"),
                $f->integer("version", -1),
                $f->bodyText("note"),
            ),
        );
    }
    public function commit(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commit(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("catalog_id"),
                $f->id("operation_id"),
                $f->integer("version", -1),
                $f->bodyText("note"),
                $f->bodyText("review_token"),
            ),
        );
    }
    public function history(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalText("cursor", 4096),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
}
