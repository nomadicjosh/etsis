<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSectionCourseBindingController
{
    public function __construct(private SectionCourseBindingService $service)
    {
    }

    public function show(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->show(new EntityId($id), FormInput::from($request)->optionalText("cursor", 16384)),
        );
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(new EntityId($id), $f->id("version_id"), $f->bodyText("reason")),
        );
    }

    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(new EntityId($id), FormInput::from($request)->text("review_token", 65536)),
        );
    }
}
