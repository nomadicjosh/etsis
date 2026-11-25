<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\GraduateCourseApprovalService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGraduateApprovalController
{
    public function __construct(private GraduateCourseApprovalService $service)
    {
    }

    public function view(ServerRequest $request, string $student, string $section): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->view(
                new EntityId($student),
                new EntityId($section),
                $f->optionalId("pursuit_id"),
            ),
        );
    }

    public function review(ServerRequest $request, string $student, string $section): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($section),
                $f->id("pursuit_id"),
                $f->text("action", 32),
                $f->integer("version"),
                $f->bodyText("reason"),
            ),
        );
    }

    public function confirm(ServerRequest $request, string $student, string $section): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($section),
                $f->id("pursuit_id"),
                $f->id("operation_id"),
                $f->text("action", 32),
                $f->integer("version"),
                $f->bodyText("reason"),
                $f->text("revision", 64),
                $f->text("effective_at", 27),
                $f->text("review_token", 8192),
            ),
        );
    }
}
