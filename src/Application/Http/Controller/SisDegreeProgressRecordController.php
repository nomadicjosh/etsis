<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\DegreeProgressRecordService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisDegreeProgressRecordController
{
    public function __construct(private DegreeProgressRecordService $service)
    {
    }
    public function page(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->listing(
                new EntityId($student),
                new EntityId($assignment),
                "staff",
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function ownerPage(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->listing(
                new EntityId($student),
                new EntityId($assignment),
                "owner",
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function show(string $student, string $assignment, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->show(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($id),
                "staff",
            ),
        );
    }
    public function ownerShow(string $student, string $assignment, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->show(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($id),
                "owner",
            ),
        );
    }
    public function review(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->bodyText("reason"),
            ),
        );
    }
    public function confirm(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->bodyText("review_token"),
            ),
        );
    }
}
