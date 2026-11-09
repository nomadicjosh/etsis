<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\ReportRequestService;
use Domain\Reporting\ValueObject\ReportRequestBrief;
use Domain\Reporting\ValueObject\ReportRequestChange;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisReportRequestController
{
    public function __construct(private ReportRequestService $service)
    {
    }
    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->create(
                $f->id("operation_id"),
                new ReportRequestBrief(
                    $f->text("title", 120),
                    $f->bodyText("question"),
                    $f->bodyText("purpose"),
                    $f->optionalText("due_on", 10),
                ),
            ),
            201,
        );
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->page($f->boolean("queue"), $f->optionalText("status", 24), $f->optionalId("after")),
        );
    }
    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->history(new EntityId($id), $f->optionalInteger("after_version") ?? -1));
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->deliveryReview(new EntityId($id), FormInput::from($request)->id("report_id")),
        );
    }
    public function change(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $note = is_array($body) ? $body["note"] ?? "" : "";
        if (!is_string($note)) {
            throw new ValidationException([["field" => "note", "message" => "Enter a text message."]]);
        }
        return new JsonResponse(
            $this->service->change(
                new EntityId($id),
                $f->id("operation_id"),
                $f->integer("version"),
                new ReportRequestChange(
                    $f->text("action", 16),
                    $note,
                    $f->optionalId("report_id"),
                    $f->optionalText("delivery_revision", 64),
                ),
            ),
        );
    }
    public function receipt(string $id, string $operation): ResponseInterface
    {
        return new JsonResponse($this->service->receipt(new EntityId($id), new EntityId($operation)));
    }
}
