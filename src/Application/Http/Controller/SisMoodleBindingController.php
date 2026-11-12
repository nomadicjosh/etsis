<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleBindingService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodleBindingController
{
    public function __construct(private MoodleBindingService $service)
    {
    }

    public function status(): ResponseInterface
    {
        return new JsonResponse($this->service->status());
    }

    public function review(ServerRequest $request): ResponseInterface
    {
        try {
            return new JsonResponse($this->service->review(FormInput::from($request)->text("reason", 500)));
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }

    public function confirm(ServerRequest $request): ResponseInterface
    {
        try {
            return new JsonResponse(
                $this->service->confirm(FormInput::from($request)->text("review_token", 16384)),
            );
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }

    public function verify(): ResponseInterface
    {
        try {
            return new JsonResponse($this->service->verify());
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }
}
