<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleAccountRequestService;
use Domain\Lms\Service\MoodleAccountResolutionService;
use Domain\Lms\Service\MoodleAccountReconciliationService;
use Domain\Lms\ValueObject\MoodleAccountDraft;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodleAccountRequestController
{
    public function __construct(
        private MoodleAccountRequestService $service,
        private MoodleAccountResolutionService $resolutions,
        private MoodleAccountReconciliationService $reconciliation,
    ) {
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
                    new MoodleAccountDraft(
                        $input->text("username", 100),
                        $input->text("email", 100),
                        $input->text("sign_in", 20),
                    ),
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

    public function reviewResolution(ServerRequest $request, string $person): ResponseInterface
    {
        return new JsonResponse(
            $this->resolutions->review(new EntityId($person), FormInput::from($request)->text("reason", 500)),
        );
    }

    public function confirmResolution(ServerRequest $request, string $person): ResponseInterface
    {
        return new JsonResponse(
            $this->resolutions->confirm(
                new EntityId($person),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function inspectReceipt(string $person): ResponseInterface
    {
        try {
            return new JsonResponse($this->reconciliation->inspect(new EntityId($person)));
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }
}
