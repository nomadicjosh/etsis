<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradebookController
{
    public function __construct(
        private GradebookService $service,
        private \Domain\AcademicRecord\Service\AcademicCompletionService $completion,
    ) {
    }

    public function assignment(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $assignment = new Assignment(
            EntityId::generate(),
            new EntityId($id),
            $f->text("title"),
            $f->integer("possible_hundredths", 1, 1000000),
            $f->date("due_on"),
        );
        $this->service->addAssignment($assignment);

        return new JsonResponse(["id" => $assignment->id->value], 201);
    }

    public function showAssignment(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->assignment(new EntityId($id)));
    }

    public function editAssignment(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->editAssignment(
            new EntityId($id),
            $f->text("title"),
            $f->integer("possible_hundredths", 1, 1000000),
            $f->date("due_on"),
            $f->text("revision", 64),
        );

        return new JsonResponse(["status" => "saved"]);
    }

    public function scorePage(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->scorePage(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function attendanceHistory(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->attendanceHistory(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function score(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->score(
            new EntityId($id),
            $f->id("student_id"),
            $f->optionalInteger("earned_hundredths"),
            $f->integer("version"),
        );

        return new JsonResponse(["status" => "graded"]);
    }

    public function gradeChoices(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->gradeChoices(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("assignment_id"),
            ),
        );
    }
    public function reviewFinalGrade(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewFinalGrade(
                $f->id("student_id"),
                new EntityId($id),
                $f->id("grade_id"),
                $f->integer("version", 0),
                $f->optionalId("assignment_id"),
                $this->reason($request, $f),
                $f->optionalText("effective_on", 10),
            ),
        );
    }
    public function commitFinalGrade(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commitFinalGrade(
                $f->id("student_id"),
                new EntityId($id),
                $f->text("review_token", 65536),
            ),
        );
    }
    public function gradeHistory(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->gradeHistory(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    public function reviewCompletion(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists("completed_on", $body)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "completed_on",
                    "message" => "Enter an actual completion date or explicitly clear the date.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->completion->review(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->optionalText("completed_on", 10),
                $f->integer("version", 0),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commitCompletion(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->completion->commit(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function attendance(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->attendance(
            $f->id("student_id"),
            new EntityId($id),
            $f->date("held_on"),
            $f->text("status", 12),
            $f->optionalText("revision", 64),
        );

        return new JsonResponse(["status" => "recorded"]);
    }

    public function scores(string $id, string $student): ResponseInterface
    {
        return new JsonResponse([
            "scores" => $this->service->scores(new EntityId($student), new EntityId($id)),
        ]);
    }
    private function reason(ServerRequest $request, FormInput $f): string
    {
        $body = $request->getParsedBody();
        return !is_array($body) || !array_key_exists("reason", $body) || $body["reason"] === ""
        ? ""
        : $f->bodyText("reason");
    }
}
