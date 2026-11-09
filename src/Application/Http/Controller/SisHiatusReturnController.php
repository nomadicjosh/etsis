<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\HiatusReturnService;
use Domain\Student\ValueObject\HiatusReturnPlan;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisHiatusReturnController
{
    public function __construct(private HiatusReturnService $service)
    {
    }

    public function review(ServerRequest $request, string $student, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->date("return_on"),
            ),
        );
    }

    public function commit(ServerRequest $request, string $student, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commit(
                new EntityId($student),
                new EntityId($id),
                new HiatusReturnPlan(
                    $f->id("operation_id"),
                    $f->date("return_on"),
                    $f->text("revision", 64),
                    $f->requiredBoolean("reactivate"),
                ),
                $f->bodyText("note"),
            ),
            201,
        );
    }

    public function show(string $student, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->service->saved(new EntityId($student), new EntityId($id), new EntityId($operation)),
        );
    }
}
