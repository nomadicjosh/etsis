<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\CampusContentService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPortalEditorController
{
    public function __construct(private CampusContentService $service)
    {
    }

    public function get(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($id)));
    }

    public function save(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(['id' => $this->service->save(
            $f->optionalId('id'),
            $f->text('kind', 12),
            $f->text('code', 80),
            $f->text('title'),
            $f->bodyText('content'),
            $f->text('audience', 8),
            $f->optionalInteger('version')
        )->value, 'status' => 'draft'], 201);
    }
}
