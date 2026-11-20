<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptFulfillmentService;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTranscriptFulfillmentController
{
    public function __construct(
        private TranscriptFulfillmentService $service,
    ) {
    }

    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }

    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->review(
            new EntityId($id),
            FormInput::from($request)->optionalText('layout_code', 40)
        ));
    }

    public function preview(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new HtmlResponse($this->service->preview(
            new EntityId($id),
            $f->id('operation_id'),
            $f->text('revision', 64),
            $f->text('review_token', 8192),
            $f->optionalText('layout_code', 40)
        ), 200, [
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; "
                    . "base-uri 'none'; form-action 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    public function prepare(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->prepare(
                new EntityId($id),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->requiredBoolean("release_reviewed"),
                $f->optionalText("review_token", 8192) ?? "",
                $f->optionalText('layout_code', 40),
            ),
            201,
        );
    }

    public function show(string $id, string $document): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view(new EntityId($id), new EntityId($document)),
        );
    }

    public function html(string $id, string $document): ResponseInterface
    {
        return new HtmlResponse(
            $this->service->html(new EntityId($id), new EntityId($document)),
            200,
            [
                "Content-Security-Policy" =>
                    "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; " .
                    "frame-ancestors 'none'; form-action 'none'",
                "X-Content-Type-Options" => "nosniff",
            ],
        );
    }

    public function pdf(string $id, string $document): ResponseInterface
    {
        $bytes = $this->service->pdf(
            new EntityId($id),
            new EntityId($document),
        );
        $response = new Response("php://memory", 200, [
            "Content-Type" => "application/pdf",
            "Content-Disposition" =>
                'attachment; filename="transcript-' .
                new EntityId($document)->value .
                '.pdf"',
            "X-Content-Type-Options" => "nosniff",
        ]);
        $response->getBody()->write($bytes);
        $response->getBody()->rewind();
        return $response;
    }

    public function reviewDelivery(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
        string $document,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewDelivery(
                new EntityId($id),
                new EntityId($document),
                $f->integer("version"),
                $f->instant("delivered_at"),
                $f->bodyText("evidence"),
            ),
        );
    }

    public function deliver(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
        string $document,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->deliver(
                new EntityId($id),
                new EntityId($document),
                $f->id("operation_id"),
                $f->integer("version"),
                $f->instant("delivered_at"),
                $f->bodyText("evidence"),
                $f->optionalText("review_token", 8192) ?? "",
            ),
        );
    }
}
