<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\DegreeAudit\Service\DegreeAssistanceService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisDegreeAssistanceController
{
    public function __construct(private DegreeAssistanceService $service)
    {
    }
    public function preview(string $id): ResponseInterface
    {
        try {
            return new JsonResponse($this->service->preview(new EntityId($id)));
        } catch (AssistanceUnavailableException $exception) {
            return new JsonResponse(["error" => $exception->getMessage()], 503);
        }
    }
    public function suggest(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        try {
            return new JsonResponse(
                $this->service->suggest(new EntityId($id), $f->boolean("consent"), $f->text("revision", 64)),
            );
        } catch (AssistanceUnavailableException $exception) {
            return new JsonResponse(["error" => $exception->getMessage()], 503);
        }
    }
}
