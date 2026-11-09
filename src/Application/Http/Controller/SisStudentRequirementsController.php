<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\DegreeAudit\ValueObject\PursuitStart;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentRequirementsController
{
    public function __construct(private StudentRequirementsService $service)
    {
    }
    public function view(string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($assignment)));
    }
    public function choices(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->choices(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    public function review(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("template_id"),
                $f->id("requirements_version_id"),
                new PursuitStart($f->text("pursuit_started_on", 10)),
                $f->bodyText("reason"),
            ),
        );
    }
    public function assign(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->assign(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->text("review_token", 32768),
            ),
        );
    }
    public function history(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
}
