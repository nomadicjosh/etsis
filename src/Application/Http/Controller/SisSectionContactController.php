<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionContactHoursService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSectionContactController
{
    public function __construct(private SectionContactHoursService $service)
    {
    }
    public function view(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->save(
            new EntityId($id),
            $f->optionalInteger("weekly_minutes"),
            $f->integer("version"),
        );
        return new JsonResponse(["status" => "saved"]);
    }
}
