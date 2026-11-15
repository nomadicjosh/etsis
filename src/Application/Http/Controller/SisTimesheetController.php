<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\HumanResources\Service\TimesheetService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTimesheetController
{
    public function __construct(private TimesheetService $service)
    {
    }

    public function employee(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addEmployee($f->id('person_id'), $f->text('job_title'));

        return new JsonResponse(['id' => $id->value,
            'institutional_id' => $this->service->employeeProfile($id)->person->institutionalId->value], 201);
    }

    public function submit(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $entry = $this->service->submit($f->id('employee_id'), new TimeSlot(
            $f->instant('starts_at'),
            $f->instant('ends_at')
        ), $f->optionalId('position_id'), $f->optionalInteger('position_version'));

        return new JsonResponse(['id' => $entry->id->value, 'status' => $entry->status], 201);
    }

    public function workPositions(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->workPositions(new EntityId($id), new TimeSlot(
            $f->instant('starts_at'),
            $f->instant('ends_at')
        )));
    }

    public function decide(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->decide(new EntityId($id), $f->requiredBoolean('approve'), $f->integer('version'));

        return new JsonResponse(['status' => 'decided']);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->entryDetails(new EntityId($id)));
    }

    public function employeeProfile(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->employeeDetails(new EntityId($id)));
    }

    public function editEmployee(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->editEmployee(
            new EntityId($id),
            $f->text('job_title'),
            $f->requiredBoolean('active'),
            $f->text('revision', 64),
            $f->bodyText('reason')
        );

        return new JsonResponse(['status' => 'saved']);
    }

    public function entries(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->entries(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse($this->service->historyPage(
            new EntityId($id),
            $input->optionalInteger('after') ?? 0,
            $input->optionalInteger('through_version')
        ));
    }

    public function week(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->week(
            new EntityId($id),
            FormInput::from($request)->optionalText('week', 10)
        ));
    }
}
