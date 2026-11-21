<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\EnrollmentReinstatementService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisEnrollmentReinstatementController
{
    public function __construct(private EnrollmentReinstatementService $service)
    {
    }
    public function review(
        ServerRequest $request,
        string $student,
        string $section,
        string $enrollment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($section),
                new EntityId($enrollment),
                FormInput::from($request)->bodyText("reason"),
            ),
        );
    }
    public function confirm(
        ServerRequest $request,
        string $student,
        string $section,
        string $enrollment,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($section),
                new EntityId($enrollment),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->text("review_token", 8192),
            ),
        );
    }
}
