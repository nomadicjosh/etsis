<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramAssignmentService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentProgramAssignmentController
{
    public function __construct(private StudentProgramAssignmentService $service)
    {
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->id("program_id"),
                $f->id("entry_term_id"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 100000),
            ),
            201,
        );
    }
}
