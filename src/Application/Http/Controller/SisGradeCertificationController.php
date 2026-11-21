<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Service\GradeCertificationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradeCertificationController
{
    public function __construct(private GradeCertificationService $service)
    {
    }
    public function readiness(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->readiness(new EntityId($id)));
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->text("reason", 1000),
                $f->boolean("acknowledged_history_gaps"),
            ),
        );
    }
    public function confirm(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($id),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->integer("expected_version", 0),
                $f->text("reason", 1000),
                $f->boolean("acknowledged_history_gaps"),
                $f->text("review_token", 8192),
            ),
        );
    }
    public function listing(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->listing(
                new EntityId($id),
                new FormInput($request->getQueryParams())->optionalInteger("before") ?? 0,
            ),
        );
    }
    public function detail(string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->service->detail(new EntityId($id), new EntityId($operation)),
        );
    }
}
