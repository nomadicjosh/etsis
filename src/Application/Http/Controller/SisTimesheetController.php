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
        ));

        return new JsonResponse(['id' => $entry->id->value, 'status' => $entry->status], 201);
    }

    public function decide(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->decide(new EntityId($id), FormInput::from($request)->boolean('approve'));

        return new JsonResponse(['status' => 'decided']);
    }

    public function show(string $id): ResponseInterface
    {
        $entry = $this->service->get(new EntityId($id));
        $profile = $this->service->employeeProfile($entry->employeeId);

        return new JsonResponse(['id' => $entry->id->value, 'employee_id' => $entry->employeeId->value,
            'status' => $entry->status, 'version' => $entry->version,
            'institutional_id' => $profile->person->institutionalId->value,
            'starts_at' => $entry->work->startsAt->format(DATE_ATOM),
                'ends_at' => $entry->work->endsAt->format(DATE_ATOM)]);
    }

    public function employeeProfile(string $id): ResponseInterface
    {
        $profile = $this->service->employeeProfile(new EntityId($id));

        return new JsonResponse(['id' => $profile->employee->id->value,
            'person_id' => $profile->employee->personId->value,
            'institutional_id' => $profile->person->institutionalId->value,
            'job_title' => $profile->employee->jobTitle, 'active' => $profile->employee->active]);
    }
}
