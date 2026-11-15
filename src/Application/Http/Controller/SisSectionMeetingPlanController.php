<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionMeetingPlanService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSectionMeetingPlanController
{
    public function __construct(private SectionMeetingPlanService $plans)
    {
    }

    public function setup(string $id): ResponseInterface
    {
        return new JsonResponse($this->plans->setup(new EntityId($id)));
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->plans->review(new EntityId($id), FormInput::from($request)->bodyText('reason')));
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->plans->publish(
            new EntityId($id),
            FormInput::from($request)->text('review_token', 1048576)
        ));
    }

    public function versions(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->plans->versions(
            new EntityId($id),
            FormInput::from($request)->optionalText('cursor', 16384)
        ));
    }
}
