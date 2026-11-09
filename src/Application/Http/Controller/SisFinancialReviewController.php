<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Finance\Service\FinancialReviewService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisFinancialReviewController
{
    public function __construct(private FinancialReviewService $service)
    {
    }

    public function request(string $student, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->request(new EntityId($student), new EntityId($id)), 201);
    }

    public function progress(string $student, string $id): ResponseInterface
    {
        return new JsonResponse(["review" => $this->service->progress(new EntityId($student), new EntityId($id))]);
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->page($f->optionalText("status", 24), $f->optionalId("after")));
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function change(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->change(
                new EntityId($id),
                $f->id("operation_id"),
                $f->integer("version"),
                $f->text("action", 24),
                $f->bodyText("note"),
            ),
        );
    }

    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(new EntityId($id), FormInput::from($request)->optionalId("after")),
        );
    }
}
