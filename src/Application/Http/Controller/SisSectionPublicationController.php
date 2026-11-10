<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSectionPublicationController
{
    public function __construct(private SectionPublicationService $service)
    {
    }

    public function setup(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->setup(new EntityId($id)));
    }

    public function review(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->text("instruction_starts_on", 10),
                $f->text("instruction_ends_on", 10),
                $f->bodyText("reason"),
            ),
        );
    }

    public function publish(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->versions(
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
}
