<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodlePersonLinkService;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodlePersonLinkController
{
    public function __construct(private MoodlePersonLinkService $service)
    {
    }

    public function status(string $person): ResponseInterface
    {
        return new JsonResponse($this->service->status(new EntityId($person)));
    }

    public function review(ServerRequest $request, string $person): ResponseInterface
    {
        $input = FormInput::from($request);
        try {
            return new JsonResponse(
                $this->service->review(
                    new EntityId($person),
                    new MoodleExternalId($input->text("moodle_user_id", 19)),
                    $input->text("reason", 500),
                ),
            );
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }

    public function confirm(ServerRequest $request, string $person): ResponseInterface
    {
        try {
            return new JsonResponse(
                $this->service->confirm(
                    new EntityId($person),
                    FormInput::from($request)->text("review_token", 16384),
                ),
            );
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }
}
