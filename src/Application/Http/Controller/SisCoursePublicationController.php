<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCoursePublicationController
{
    public function __construct(private CoursePublicationService $service)
    {
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(new EntityId($id), $f->bodyText("reason")));
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 524288),
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
