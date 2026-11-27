<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Graduation\Service\GraduationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGraduationController
{
    public function __construct(private GraduationService $service)
    {
    }

    public function current(
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->current(
                new EntityId($student),
                new EntityId($assignment),
                "staff",
            ),
        );
    }

    public function owner(
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->current(
                new EntityId($student),
                new EntityId($assignment),
                "owner",
            ),
        );
    }

    public function history(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->timeline(
                new EntityId($student),
                new EntityId($assignment),
                "staff",
                FormInput::from($request)->optionalText("cursor", 4096),
            ),
        );
    }

    public function ownerHistory(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->timeline(
                new EntityId($student),
                new EntityId($assignment),
                "owner",
                FormInput::from($request)->optionalText("cursor", 4096),
            ),
        );
    }

    public function review(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($assignment),
                $input->text("action", 20),
                self::array($request, "request"),
                $input->bodyText("reason"),
            ),
        );
    }

    public function confirm(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($assignment),
                $input->id("operation_id"),
                $input->text("action", 20),
                self::array($request, "request"),
                $input->text("revision", 64),
                $input->bodyText("reason"),
                $input->bodyText("review_token"),
            ),
        );
    }

    public function queue(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->queue(
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function reviewGroup(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->reviewGroup(
                self::array($request, "candidates"),
                FormInput::from($request)->bodyText("reason"),
            ),
        );
    }

    public function confirmGroup(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->confirmGroup(self::array($request, "reviews")),
        );
    }

    private static function array(ServerRequest $request, string $key): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body[$key] ?? null)) {
            throw new \InvalidArgumentException(
                "Provide the selected graduation details.",
            );
        }
        return $body[$key];
    }
}
