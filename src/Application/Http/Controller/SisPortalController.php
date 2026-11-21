<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\PortalService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPortalController
{
    public function __construct(
        private PortalService $service,
        private \Domain\Portal\Service\ContentMarkup $markup,
    ) {
    }

    public function create(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $post = $this->service->create(
            $f->text("kind", 12),
            $f->text("code", 80),
            $f->text("title"),
            $f->bodyText("content"),
        );

        return new JsonResponse(["id" => $post->id->value, "status" => $post->status], 201);
    }

    public function show(string $code): ResponseInterface
    {
        $post = $this->service->published($code);

        return new JsonResponse([
            "id" => $post->id->value,
            "kind" => $post->kind,
            "code" => $post->code,
            "title" => $post->title,
            "content" =>
                $post->contentFormat === "html" ? $this->markup->sanitize($post->content) : $post->content,
            "content_format" => $post->contentFormat,
            "content_html" =>
                $post->contentFormat === "html" ? $this->markup->sanitize($post->content) : null,
        ]);
    }
}
