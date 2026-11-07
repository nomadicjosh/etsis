<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\GradeDefinition;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradebookController
{
    public function __construct(private GradebookService $service)
    {
    }

    public function defineGrade(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $grade = new GradeDefinition(
            EntityId::generate(),
            $f->text('code', 12),
            $f->integer('points_milli', 0, 10000),
            $f->boolean('passes'),
            $f->boolean('counts_gpa')
        );
        $this->service->defineGrade($grade);

        return new JsonResponse(['id' => $grade->id->value], 201);
    }

    public function assignment(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $assignment = new Assignment(
            EntityId::generate(),
            new EntityId($id),
            $f->text('title'),
            $f->integer('possible_hundredths', 1, 1000000),
            $f->date('due_on')
        );
        $this->service->addAssignment($assignment);

        return new JsonResponse(['id' => $assignment->id->value], 201);
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
            $f->text('title'),
            $f->integer('possible_hundredths', 1, 1000000),
            $f->date('due_on'),
            $f->text('revision', 64)
        );

        return new JsonResponse(['status' => 'saved']);
    }

    public function scorePage(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->scorePage(
            new EntityId($student),
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function attendanceHistory(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->attendanceHistory(
            new EntityId($student),
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function score(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->score(
            new EntityId($id),
            $f->id('student_id'),
            $f->optionalInteger('earned_hundredths'),
            $f->integer('version')
        );

        return new JsonResponse(['status' => 'graded']);
    }

    public function finalGrade(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->finalGrade($f->id('student_id'), new EntityId($id), $f->id('grade_id'), $f->integer('version'));

        return new JsonResponse(['status' => 'graded']);
    }

    public function attendance(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->attendance(
            $f->id('student_id'),
            new EntityId($id),
            $f->date('held_on'),
            $f->text('status', 12),
            $f->optionalText('revision', 64)
        );

        return new JsonResponse(['status' => 'recorded']);
    }

    public function scores(string $id, string $student): ResponseInterface
    {
        return new JsonResponse(['scores' => $this->service->scores(new EntityId($student), new EntityId($id))]);
    }
}
