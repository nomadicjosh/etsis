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
    public function __construct(private PortalService $service)
    {
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $post = $this->service->create(
            $f->text('kind', 12),
            $f->text('code', 80),
            $f->text('title'),
            $f->bodyText('content')
        );

        return new JsonResponse(['id' => $post->id->value, 'status' => $post->status], 201);
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->publish(new EntityId($id), $f->boolean('publish', true), $f->integer('version'));

        return new JsonResponse(['status' => 'changed']);
    }

    public function show(string $code): ResponseInterface
    {
        $post = $this->service->published($code);

        return new JsonResponse(['id' => $post->id->value, 'kind' => $post->kind, 'code' => $post->code,
            'title' => $post->title, 'content' => $post->content]);
    }
}
