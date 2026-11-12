<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleAdministrationService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class SisMoodleController
{
    public function __construct(private MoodleAdministrationService $service)
    {
    }
    public function status(): ResponseInterface
    {
        return new JsonResponse($this->service->status());
    }
    public function check(): ResponseInterface
    {
        try {
            return new JsonResponse($this->service->check());
        } catch (MoodleConnectionUnavailableException $exception) {
            return new JsonResponse(["error" => $exception->getMessage()], 503);
        }
    }
}
