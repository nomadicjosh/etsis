<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Service\AdvisingAppointmentService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdvisingAppointmentController
{
    public function __construct(
        private AdvisingAppointmentService $service,
        private \Application\Service\AdvisingAppointmentExport $export,
    ) {
    }

    public function staffList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'list');
    }

    public function advisorList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'list');
    }

    public function ownerList(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'owner', 'list');
    }

    public function staffCalendar(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'calendar');
    }

    public function advisorCalendar(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'calendar');
    }

    public function ownerCalendar(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'owner', 'calendar');
    }

    public function myCalendar(#[\SensitiveParameter] ServerRequest $r): ResponseInterface
    {
        $f = FormInput::from($r);
        return new JsonResponse($this->service->calendar(
            'owner',
            null,
            null,
            $f->optionalText('date', 10),
            $f->optionalText('view', 5) ?? 'week',
            $f->boolean('include_cancelled')
        ));
    }

    public function export(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse(['calendar' => $this->export->prepare(
            $form->optionalText('date', 10),
            $form->optionalText('view', 5) ?? 'week',
            $form->boolean('include_cancelled'),
        )]);
    }

    public function bookingChoices(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $f = FormInput::from($r);
        return new JsonResponse($this->service->bookingChoices(
            new \Domain\Shared\ValueObject\EntityId($student),
            new \Domain\Shared\ValueObject\EntityId($pursuit),
            $f->optionalId('host_person_id'),
            $f->optionalText('date', 10),
            $f->optionalId('window_id')
        ));
    }

    public function book(#[\SensitiveParameter] ServerRequest $r, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($r);
        return new JsonResponse($this->service->book(
            new \Domain\Shared\ValueObject\EntityId($student),
            new \Domain\Shared\ValueObject\EntityId($pursuit),
            $f->id('appointment_id'),
            $f->id('window_id'),
            $f->integer('window_version'),
            $f->text('start', 16)
        ));
    }

    public function staffSchedule(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'schedule');
    }

    public function advisorSchedule(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'schedule');
    }

    public function staffAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'staff', 'act');
    }

    public function advisorAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'advisor', 'act');
    }

    public function ownerAct(
        #[\SensitiveParameter] ServerRequest $r,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($r, $student, $pursuit, 'owner', 'act');
    }

    private function dispatch(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
        string $channel,
        string $method,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $s = new \Domain\Shared\ValueObject\EntityId($student);
        $p = new \Domain\Shared\ValueObject\EntityId($pursuit);
        if ($method === 'list') {
            return new JsonResponse($this->service->listing(
                $s,
                $p,
                $channel,
                $f->boolean('finished'),
                $f->optionalId('after'),
                $f->optionalId('appointment_id'),
            ));
        }
        if ($method === 'calendar') {
            return new JsonResponse($this->service->calendar(
                $channel,
                $s,
                $p,
                $f->optionalText('date', 10),
                $f->optionalText('view', 5) ?? 'week',
                $f->boolean('include_cancelled')
            ));
        }
        if ($method === 'schedule') {
            return new JsonResponse($this->service->schedule(
                $s,
                $p,
                $channel,
                $f->id('appointment_id'),
                $f->id('host_person_id'),
                $f->text('start', 16),
                $f->text('end', 16),
                $f->text('timezone', 80),
                $f->bodyText('message')
            ));
        }
        $action = $f->text('action', 16);
        $time = $action === 'reschedule' ? new AppointmentTime(
            $f->text('start', 16),
            $f->text('end', 16),
            $f->text('timezone', 80)
        ) : null;
        return new JsonResponse($this->service->act(
            $s,
            $p,
            $channel,
            $f->id('appointment_id'),
            $f->id('operation_id'),
            $action,
            $f->integer('version'),
            $time,
            $f->bodyText('message')
        ));
    }
}
