<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Service\MoodleSectionSyncService;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodleSectionController
{
    public function __construct(private MoodleSectionSyncService $service)
    {
    }
    public function directory(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->directory(new FormInput($request->getQueryParams())->search()),
        );
    }
    public function link(ServerRequest $request, string $section): ResponseInterface
    {
        $input = FormInput::from($request);
        try {
            return new JsonResponse(
                $this->service->link(
                    new EntityId($section),
                    new MoodleExternalId($input->text("course", 20)),
                    $input->text("reason", 500),
                    $input->requiredBoolean("acknowledged"),
                ),
            );
        } catch (MoodleConnectionUnavailableException $error) {
            return new JsonResponse(["error" => $error->getMessage()], 503);
        }
    }
}
