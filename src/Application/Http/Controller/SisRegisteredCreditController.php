<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\AcademicRecord\Service\RegisteredCreditService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegisteredCreditController
{
    public function __construct(private RegisteredCreditService $service)
    {
    }
    public function view(string $student, string $section, string $enrollment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view(
                new EntityId($student),
                new EntityId($section),
                new EntityId($enrollment),
            ),
        );
    }
    public function review(
        ServerRequest $request,
        string $student,
        string $section,
        string $enrollment,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($section),
                new EntityId($enrollment),
                $f->integer("expected_version", 0),
                $f->integer("expected_position", 1),
                $f->integer("registered_milli", 0, 999999),
                $f->text("reason", 1000),
                $f->boolean("acknowledged"),
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
                $f->integer("expected_version", 0),
                $f->integer("expected_position", 1),
                $f->integer("previous_registered_milli", 0, 2147483646),
                $f->integer("registered_milli", 0, 999999),
                $f->text("reason", 1000),
                $f->boolean("acknowledged"),
                $f->text("review_token", 8192),
            ),
        );
    }
    public function history(
        ServerRequest $request,
        string $student,
        string $section,
        string $enrollment,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($section),
                new EntityId($enrollment),
                FormInput::from($request)->optionalText("cursor", 8192),
            ),
        );
    }
}
