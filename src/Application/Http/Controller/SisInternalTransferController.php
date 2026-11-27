<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Transfer\Service\InternalTransferService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisInternalTransferController
{
    public function __construct(private InternalTransferService $service)
    {
    }
    public function page(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->page(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function candidates(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->candidates(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function show(
        string $student,
        string $assignment,
        string $award,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->view(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($award),
            ),
        );
    }
    public function history(
        ServerRequest $request,
        string $student,
        string $assignment,
        string $award,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->timeline(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($award),
                FormInput::from($request)->optionalText("cursor", 4096),
            ),
        );
    }
    public function search(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->service->search(
                new EntityId($student),
                new EntityId($assignment),
                $i->search(),
                $i->optionalId("after"),
            ),
        );
    }
    public function publications(
        ServerRequest $request,
        string $student,
        string $assignment,
        string $course,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->publications(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($course),
                FormInput::from($request)->optionalText("cursor", 4096),
            ),
        );
    }
    public function review(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($assignment),
                $i->text("action", 12),
                $this->details($request),
                $i->bodyText("reason"),
            ),
        );
    }
    public function confirm(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($assignment),
                $i->id("operation_id"),
                $i->text("action", 12),
                $this->details($request),
                $i->bodyText("reason"),
                $i->text("revision", 64),
                $i->bodyText("review_token"),
            ),
        );
    }
    private function details(ServerRequest $request): array
    {
        $r = $request->getParsedBody()["request"] ?? null;
        if (!is_array($r)) {
            throw new \InvalidArgumentException(
                "Explicit receiving request required.",
            );
        }
        return $r;
    }
}
