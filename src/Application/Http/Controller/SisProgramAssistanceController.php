<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Catalog\Service\ProgramAssistanceService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisProgramAssistanceController
{
    public function __construct(private ProgramAssistanceService $service)
    {
    }
    public function preview(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->preview(new EntityId($id)));
    }
    public function suggest(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $task = AuthoringTask::tryFrom($f->text("task", 30));
        if ($task === null) {
            throw new \InvalidArgumentException("Choose an authoring task.");
        }
        try {
            return new JsonResponse(
                $this->service->suggest(
                    new EntityId($id),
                    $task,
                    $f->boolean("consent"),
                    $f->text("revision", 64),
                ),
            );
        } catch (AssistanceUnavailableException $exception) {
            return new JsonResponse(["error" => $exception->getMessage()], 503);
        }
    }
}
