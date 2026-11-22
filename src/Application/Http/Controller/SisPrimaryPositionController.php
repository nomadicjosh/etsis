<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\HumanResources\Service\PrimaryPositionService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPrimaryPositionController
{
    public function __construct(private PrimaryPositionService $service)
    {
    }
    public function get(string $employee): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($employee)));
    }
    public function review(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($employee),
                $f->id("operation_id"),
                $f->integer("version"),
                $f->optionalId("position_id"),
                $f->optionalInteger("position_version"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function confirm(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->confirm(
            new EntityId($employee),
            $f->id("operation_id"),
            $f->integer("version"),
            $f->optionalId("position_id"),
            $f->optionalInteger("position_version"),
            $f->bodyText("reason"),
            $f->text("review_token", 8192),
        );
        return new JsonResponse(["status" => "saved"]);
    }
    public function history(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->history(
                new EntityId($employee),
                $f->optionalInteger("after") ?? 0,
                $f->optionalInteger("through_version"),
            ),
        );
    }
}
