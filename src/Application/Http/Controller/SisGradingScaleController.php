<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradingScaleController
{
    public function __construct(private GradingScaleService $service)
    {
    }
    public function global(): ResponseInterface
    {
        return new JsonResponse($this->service->view());
    }
    public function view(string $program): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($program)));
    }
    public function review(ServerRequest $request, ?string $program = null): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists("definition", $body) ||
            ($body["definition"] !== null && !is_array($body["definition"]))
        ) {
            throw new \InvalidArgumentException(
                "Supply a grading definition or null to inherit the global scale.",
            );
        }
        $reason = $body["reason"] ?? "";
        if (!is_string($reason)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "reason",
                    "message" => "Enter a reason for this grading policy change.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->service->review(
                $program === null ? null : new EntityId($program),
                $body["definition"] === null ? null : new GradingScaleDefinition($body["definition"]),
                $reason,
            ),
        );
    }
    public function commit(ServerRequest $request, ?string $program = null): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                $program === null ? null : new EntityId($program),
                FormInput::from($request)->text("review_token", 65536),
            ),
        );
    }
}
