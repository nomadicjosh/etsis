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
        return new JsonResponse(
            $this->service->view(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function change(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->change(
            new EntityId($student),
            new EntityId($assignment),
            $f->id("catalog_id"),
            $f->id("operation_id"),
            $f->integer("version", -1),
            $f->bodyText("note"),
        );
        return new JsonResponse(["status" => "saved"]);
    }
    public function history(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
}
